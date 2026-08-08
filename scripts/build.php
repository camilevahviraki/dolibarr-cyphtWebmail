#!/usr/bin/env php
<?php
/* Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        scripts/build.php
 * \ingroup     cyphtWebmail
 * \brief       Command line build, in two modes.
 *
 *              prepare  Fetch dependencies and install this module's Cypht
 *                       module sets. Needs neither Dolibarr nor a database,
 *                       so it runs on a laptop or in CI before the module is
 *                       installed anywhere. This is what to run before zipping.
 *
 *              build    Everything prepare does, plus writing Cypht's .env
 *                       from Dolibarr's settings, compiling the app and
 *                       publishing it. Needs Dolibarr, because the .env holds
 *                       this installation's database credentials and secrets.
 *
 *              The split is not a preference. config_gen.php writes the build
 *              machine's absolute paths into public/index.php and
 *              config/dynamic.php, so a compiled app cannot be moved between
 *              machines. Ship a prepared tree; build on the target.
 */

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	fwrite(STDERR, "This script must be run from the command line.\n");
	exit(1);
}

$root = dirname(__DIR__);

$options = array(
	'mode' => 'build',
	'owner' => '',
	'group' => '',
	'quiet' => false,
	'permissions' => true,
);

foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--help' || $arg === '-h') {
		fwrite(STDOUT, <<<TEXT
Build the embedded Cypht application.

  php scripts/build.php [options]

Modes
  (default)             full build; requires Dolibarr and its database
  --prepare             dependencies and module sets only; no Dolibarr needed.
                        Run this before packaging or zipping the module.

Options
  --owner=USER          chown writable paths afterwards (POSIX only)
  --group=GROUP         chgrp writable paths afterwards (POSIX only)
  --skip-permissions    do not touch ownership or modes
  --quiet               errors and the final result only
  --help                show this

A compiled app is not portable: config_gen.php bakes absolute paths into
public/index.php. Package with --prepare, then run a full build on the
target machine, or press Generate on its module setup page.

TEXT
		);
		exit(0);
	}

	if ($arg === '--prepare') {
		$options['mode'] = 'prepare';
	} elseif ($arg === '--quiet') {
		$options['quiet'] = true;
	} elseif ($arg === '--skip-permissions') {
		$options['permissions'] = false;
	} elseif (strpos($arg, '--owner=') === 0) {
		$options['owner'] = substr($arg, 8);
	} elseif (strpos($arg, '--group=') === 0) {
		$options['group'] = substr($arg, 8);
	} else {
		fwrite(STDERR, "Unknown option: ".$arg."\nTry --help\n");
		exit(1);
	}
}

/**
 * @param string $line
 * @param bool $quiet
 * @return void
 */
function cyphtSay($line, $quiet = false)
{
	if (!$quiet) {
		fwrite(STDOUT, $line);
	}
}

/**
 * Locate a Composer runnable across platforms: a local composer.phar first,
 * then whatever is on PATH under any of the names it ships as.
 *
 * @param string $root Module root
 * @return string[]|null Command prefix, or null if none found
 */
function cyphtFindComposer($root)
{
	if (is_file($root.'/composer.phar')) {
		return array(PHP_BINARY, $root.'/composer.phar');
	}

	$names = (DIRECTORY_SEPARATOR === '\\')
		? array('composer.bat', 'composer.cmd', 'composer.phar', 'composer')
		: array('composer', 'composer.phar');

	$paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
	foreach ($names as $name) {
		foreach ($paths as $dir) {
			$candidate = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
			if (is_file($candidate)) {
				return (substr($name, -5) === '.phar')
					? array(PHP_BINARY, $candidate)
					: array($candidate);
			}
		}
	}

	return null;
}

/**
 * @param string[] $cmd
 * @param string $cwd
 * @param bool $quiet
 * @return int Exit code
 */
function cyphtRun(array $cmd, $cwd, $quiet)
{
	$line = implode(' ', array_map('escapeshellarg', $cmd));
	$descriptors = array(0 => array('pipe', 'r'), 1 => STDOUT, 2 => STDERR);

	if ($quiet) {
		$descriptors[1] = array('pipe', 'w');
	}

	$pipes = array();
	$proc = @proc_open($line, $descriptors, $pipes, $cwd);
	if (!is_resource($proc)) {
		fwrite(STDERR, "Could not start: ".$line."\n");
		return 1;
	}

	if (isset($pipes[0])) {
		fclose($pipes[0]);
	}
	if ($quiet && isset($pipes[1])) {
		stream_get_contents($pipes[1]);
		fclose($pipes[1]);
	}

	return proc_close($proc);
}

/*
 * Step 1: dependencies. Both modes need them.
 */
$composer = cyphtFindComposer($root);

if ($composer === null) {
	if (!is_dir($root.'/vendor/jason-munro/cypht')) {
		fwrite(STDERR, "Composer not found and vendor/jason-munro/cypht is missing.\n");
		fwrite(STDERR, "Install Composer, or drop a composer.phar in ".$root."\n");
		exit(1);
	}
	cyphtSay("Composer not found; using the vendor/ already on disk.\n", $options['quiet']);
} else {
	cyphtSay("== Dependencies ==\n", $options['quiet']);
	$code = cyphtRun(array_merge($composer, array('install', '--no-interaction', '--no-progress')), $root, $options['quiet']);
	if ($code !== 0) {
		fwrite(STDERR, "composer install failed (exit ".$code.").\n");
		exit(1);
	}
}

if (!is_dir($root.'/vendor/jason-munro/cypht')) {
	fwrite(STDERR, "vendor/jason-munro/cypht is still missing after install.\n");
	exit(1);
}

/*
 * Step 2: this module's Cypht module sets and the vendor layout shim. These
 * touch only files, so they work with no Dolibarr and no database.
 */
require_once $root.'/class/install/paths.class.php';
require_once $root.'/class/install/vendorlayout.class.php';
require_once $root.'/class/install/moduleinstaller.class.php';
require_once $root.'/class/install/upstreampatches.class.php';

$paths = new CyphtPaths();
$vendorLayout = new CyphtVendorLayout($paths);
$installer = new CyphtModuleInstaller($paths);
$patches = new CyphtUpstreamPatches($paths);

cyphtSay("\n== Cypht module sets ==\n", $options['quiet']);

if (!$vendorLayout->ensureCyphtVendorBridge()) {
	fwrite(STDERR, $vendorLayout->error."\n");
	exit(1);
}
if (!$installer->installAll()) {
	fwrite(STDERR, $installer->error."\n");
	exit(1);
}
cyphtSay("installed: ".implode(', ', $installer->listModuleSets())."\n", $options['quiet']);

if (!$patches->patchCoreFunctionsGuard()) {
	fwrite(STDERR, $patches->error."\n");
	exit(1);
}

if ($options['mode'] === 'prepare') {
	cyphtSay("\nPrepared. Dependencies and module sets are in place.\n", $options['quiet']);
	cyphtSay("The app itself is not compiled: config_gen.php writes absolute paths,\n", $options['quiet']);
	cyphtSay("so run a full build on the target machine, or press Generate there.\n", $options['quiet']);
	exit(0);
}

/*
 * Step 3: the full build. Dolibarr from here on, because the .env is written
 * from this installation's database credentials and stored secrets.
 */
$bootstrap = array(
	$root.'/../../master.inc.php',
	$root.'/../../../master.inc.php',
	$root.'/../../../htdocs/master.inc.php',
);

$loaded = false;
foreach ($bootstrap as $candidate) {
	if (is_file($candidate)) {
		require_once $candidate;
		$loaded = true;
		break;
	}
}

if (!$loaded) {
	fwrite(STDERR, "Could not find Dolibarr's master.inc.php from ".$root."\n");
	fwrite(STDERR, "Run with --prepare to build everything that does not need Dolibarr.\n");
	exit(1);
}

require_once $root.'/class/webmail.class.php';

global $db, $conf;

if (!isModEnabled('cyphtwebmail')) {
	// A warning, not a failure: building before enabling the module is a
	// reasonable order to do things in.
	fwrite(STDERR, "warning: the cyphtWebmail module is not enabled yet in Dolibarr.\n");
}

$webmail = new CyphtWebmail($db);

cyphtSay("\n== Build ==\n", $options['quiet']);

$result = $webmail->runConfigGen(function ($chunk, $type) use ($options) {
	if ($options['quiet'] && $type !== 'err') {
		return;
	}
	fwrite($type === 'err' ? STDERR : STDOUT, $chunk);
});

if (empty($result['success'])) {
	fwrite(STDERR, "\nBuild failed: ".$result['error']."\n");
	exit(1);
}

/*
 * Step 4: permissions. A build run from a terminal creates files owned by
 * whoever ran it; if that is not the webserver user, the next request cannot
 * write sessions, settings or a new build, and it fails far from the cause.
 */
if ($options['permissions']) {
	$writable = array(
		$webmail->getPublicPath(),
		$webmail->getDataDir(),
		$webmail->getDataDir().'/users',
		$webmail->getDataDir().'/attachments',
		$webmail->getDataDir().'/sso_sessions',
		$webmail->getCyphtPath().'/.env',
	);

	$posix = (DIRECTORY_SEPARATOR === '/');

	if (!$posix && ($options['owner'] !== '' || $options['group'] !== '')) {
		cyphtSay("\n--owner/--group ignored: this platform uses ACLs, not POSIX ownership.\n", $options['quiet']);
	}

	$warnings = array();

	foreach ($writable as $target) {
		if (!file_exists($target)) {
			if (!@mkdir($target, 0770, true) && !is_dir($target)) {
				$warnings[] = 'could not create '.$target;
				continue;
			}
		}

		if (!@chmod($target, is_dir($target) ? 0770 : 0660)) {
			$warnings[] = 'could not chmod '.$target;
		}
		if ($posix && $options['owner'] !== '' && !@chown($target, $options['owner'])) {
			$warnings[] = 'could not chown '.$target;
		}
		if ($posix && $options['group'] !== '' && !@chgrp($target, $options['group'])) {
			$warnings[] = 'could not chgrp '.$target;
		}
	}

	cyphtSay("\nPermissions checked on ".count($writable)." paths.\n", $options['quiet']);

	foreach ($warnings as $message) {
		fwrite(STDERR, "  warning: ".$message."\n");
	}

	if ($posix && $options['owner'] === '' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
		fwrite(STDERR, "\n  warning: running as root without --owner. Everything just written is\n");
		fwrite(STDERR, "           owned by root; the webserver will not be able to write to it.\n");
	}
}

cyphtSay("\nBuild complete. Cypht ".$webmail->getInstalledVersion()." is live.\n", $options['quiet']);

$db->close();
exit(0);
