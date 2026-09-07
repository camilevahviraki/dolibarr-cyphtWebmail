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
 * \file        build/buildzip.php
 * \ingroup     cyphtWebmail
 * \brief       Build a release archive: export, build, prune, zip, verify.
 *
 *              php build/buildzip.php [--version=X.Y.Z] [--out=DIR]
 *                                     [--allow-dirty] [--quiet]
 *
 *              Named and placed after Dolibarr's own
 *              htdocs/modulebuilder/template/build. It compiles Cypht through
 *              scripts/build.php rather than copying a glob of the working
 *              tree, which is the customisation that template invites.
 */

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	fwrite(STDERR, "This script must be run from the command line.\n");
	exit(1);
}

/* Forced on, because php.ini decides this otherwise and XAMPP's CLI one ships
 * display_errors off. A fatal then prints nothing at all and the script just
 * returns non-zero, which reads as "it did nothing" rather than "it broke". */
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

/* The same hole from the other side: a fatal skips every line below it, so
 * without this the last thing printed is whatever step was in progress. */
register_shutdown_function(function () {
	$fatal = error_get_last();
	if ($fatal !== null && ($fatal['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
		fwrite(STDERR, "\nFATAL: ".$fatal['message']."\n  at ".$fatal['file'].':'.$fatal['line']."\n");
	}
});

$root = dirname(__DIR__);

// dist/ inside the module, which .gitignore already covers. The parent
// directory was the old default and put releases outside the project, where
// nobody thought to look.
$options = array('out' => $root.'/dist', 'version' => '', 'allow-dirty' => false, 'quiet' => false);

foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--help' || $arg === '-h') {
		fwrite(STDOUT, <<<TEXT
Build a release archive.

  php build/buildzip.php [options]

  --version=X.Y.Z  name the archive this instead of the descriptor's version
  --out=DIR        where to write the zip (default: dist/ in the module)
  --allow-dirty    package uncommitted work; the archive will not match HEAD
  --quiet          only report the finished archive

The last commit is what gets exported; no tag is needed. Only committed files
are, so commit before packaging. The build runs in a temporary directory with
no Dolibarr above it, which is what keeps this installation's credentials out
of the archive.

The version names the file only. The directory inside the archive is always
cyphtwebmail, because Dolibarr extracts it into htdocs/custom and that name
becomes the module directory.

TEXT
		);
		exit(0);
	}
	if (strpos($arg, '--out=') === 0) {
		$options['out'] = substr($arg, 6);
		continue;
	}
	if (strpos($arg, '--version=') === 0) {
		$options['version'] = substr($arg, 10);
		continue;
	}
	if ($arg === '--allow-dirty') {
		$options['allow-dirty'] = true;
		continue;
	}
	if ($arg === '--quiet') {
		$options['quiet'] = true;
		continue;
	}
	fwrite(STDERR, "Unknown option: ".$arg."\n");
	exit(1);
}

require_once $root.'/class/install/paths.class.php';
require_once $root.'/class/install/vendorlayout.class.php';

$paths = new CyphtPaths();
$files = new CyphtVendorLayout($paths);

/**
 * @param string $line
 * @param bool $quiet
 * @return void
 */
function cyphtPkgSay($line, $quiet)
{
	if (!$quiet) {
		fwrite(STDOUT, $line);
	}
}

/**
 * @param string $message
 * @return void
 */
function cyphtPkgFail($message)
{
	fwrite(STDERR, "\n".$message."\n");
	exit(1);
}

/**
 * Run a command and return [exitCode, output].
 *
 * @param string[] $cmd
 * @param string $cwd
 * @return array{0:int,1:string}
 */
function cyphtPkgRun(array $cmd, $cwd)
{
	$cmdline = implode(' ', array_map('escapeshellarg', $cmd));

	/* Files, not pipes, for the same reason CyphtPipeline::runProcess() uses
	 * them. Draining stdout to EOF before reading stderr deadlocks as soon as
	 * the child fills the stderr buffer, roughly 64KB, and Composer writes most
	 * of its output to stderr. The staged build is composer plus config_gen, so
	 * it passes that in seconds and the whole job hangs with nothing produced.
	 *
	 * stdin is a pipe we close immediately: a subprocess that decides to prompt
	 * should get EOF and fail, not wait forever for a terminal that is not
	 * there. */
	$outFile = tempnam(sys_get_temp_dir(), 'cyphtpkg-out');
	$errFile = tempnam(sys_get_temp_dir(), 'cyphtpkg-err');

	$spec = array(
		0 => array('pipe', 'r'),
		1 => array('file', $outFile, 'w'),
		2 => array('file', $errFile, 'w'),
	);
	$proc = @proc_open($cmdline, $spec, $pipes, $cwd);

	if (!is_resource($proc)) {
		@unlink($outFile);
		@unlink($errFile);
		return array(-1, 'could not start: '.$cmdline);
	}

	if (isset($pipes[0])) {
		fclose($pipes[0]);
	}

	$code = proc_close($proc);

	$out = (string) @file_get_contents($outFile) . (string) @file_get_contents($errFile);
	@unlink($outFile);
	@unlink($errFile);

	return array($code, $out);
}

/**
 * The module's directory name, derived the way Dolibarr derives it.
 *
 * @param string $moduleRoot Tree to inspect
 * @return string
 */
function cyphtPkgModuleName($moduleRoot)
{
	$found = glob($moduleRoot . '/core/modules/mod*.class.php');

	if (!is_array($found) || count($found) !== 1) {
		cyphtPkgFail('Expected exactly one core/modules/mod*.class.php to name the module after, found '
			. (is_array($found) ? count($found) : 0) . '.');
	}

	if (!preg_match('/mod(.*)\.class\.php$/', basename($found[0]), $m)) {
		cyphtPkgFail('Could not read a module name out of ' . basename($found[0]) . '.');
	}

	return strtolower($m[1]);
}

/**
 * Digits and dots only, because admin/modules.php:262 accepts the upload
 * against /^(module[a-zA-Z0-9]*_|theme_|).*\-([0-9][0-9\.]*)\.zip$/ and strips
 * that same pattern at :292 to find the directory inside the archive. A suffix
 * like 2.0-beta1 is refused at upload.
 *
 * @param string $version
 * @return void
 */
function cyphtPkgCheckVersion($version)
{
	if (!preg_match('/^[0-9][0-9.]*$/', $version)) {
		cyphtPkgFail('Version "'.$version.'" would be refused by Dolibarr. Digits and dots only: 1.0, 1.2.3.');
	}
}

/**
 * Everything the runtime never reads, relative to the staged module root.
 *
 * The two asset packages are the bulk of it: config_gen compiles them into
 * public/site.css, public/site.js and the theme stylesheets, and the only code
 * that points a browser back at vendor/ is get_js_libs(), which runs solely
 * under DEBUG_MODE.
 *
 * Safe to drop only because the build's first step, composer install,
 * fetches them again. An installed module can rebuild itself, from the
 * setup page or on activation, so the rule is: anything a rebuild consumes
 * must either survive here or be restorable by composer. third_party/ is
 * neither, since it ships inside the jason-munro/cypht package rather than
 * as a package of its own.
 *
 * @return string[]
 */
function cyphtPkgPruneList()
{
	$cypht = 'vendor/jason-munro/cypht/';

	return array(
		'vendor/thomaspark',
		'vendor/twbs',
		$cypht.'site',
		$cypht.'site.js',
		$cypht.'site.css',
		$cypht.'fonts',
		$cypht.'tests',
		$cypht.'docker',
		$cypht.'.github',
		$cypht.'.travis',
		$cypht.'vendor/twbs',
		$cypht.'vendor/thomaspark',
		$cypht.'vendor/composer',
		'debug.log',
		'last_build_log.ndjson',
		'session_debug.log',
	);
}

/**
 * Read the zip back and refuse to ship one that is wrong.
 *
 * @param string $zipPath
 * @param string $dirName Expected archive root
 * @param array<string,string> $forbidden Substring => why it must not be there
 * @return string[] Problems found
 */
function cyphtPkgVerify($zipPath, $dirName, array $forbidden)
{
	$problems = array();

	$zip = new ZipArchive();
	if ($zip->open($zipPath) !== true) {
		return array('the archive could not be reopened for checking');
	}

	$required = array(
		$dirName.'/public/index.php' => 'the compiled entry point',
		$dirName.'/vendor/jason-munro/cypht/vendor/autoload.php' => 'the autoloader shim public/index.php requires',
		$dirName.'/vendor/autoload.php' => 'the Composer autoloader',
		$dirName.'/core/modules/modcyphtWebmail.class.php' => 'the module descriptor',
		$dirName.'/vendor/jason-munro/cypht/modules/site/lib.php' => 'the site module set, which declares Custom_Auth',
		$dirName.'/vendor/jason-munro/cypht/config/zz_dolibarr.php' => 'the config pinning auth_type and the module list',
		$dirName.'/vendor/jason-munro/cypht/third_party/cash.min.js' => 'a rebuild input; without it site.js compiles with $ undefined',
	);
	foreach ($required as $entry => $what) {
		if ($zip->locateName($entry) === false) {
			$problems[] = 'missing '.$entry.' ('.$what.')';
		}
	}

	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = $zip->getNameIndex($i);

		if (strpos($name, $dirName.'/') !== 0) {
			$problems[] = 'entry outside '.$dirName.'/: '.$name;
			break;
		}
		foreach ($forbidden as $needle => $why) {
			if (strpos($name, $needle) !== false) {
				$problems[] = $why.': '.$name;
				break 2;
			}
		}
	}

	$zip->close();

	return $problems;
}

/**
 * Add a directory to an open archive under $prefix.
 *
 * @param ZipArchive $zip
 * @param string $dir
 * @param string $prefix
 * @return int Files added
 */
function cyphtPkgAddDir(ZipArchive $zip, $dir, $prefix)
{
	$added = 0;
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($items as $item) {
		$relative = str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));

		if ($item->isDir()) {
			$zip->addEmptyDir($prefix.'/'.$relative);
			continue;
		}
		$zip->addFile($item->getPathname(), $prefix.'/'.$relative);
		$added++;
	}

	return $added;
}

// ---------------------------------------------------------------------

if (!class_exists('ZipArchive')) {
	cyphtPkgFail("PHP's zip extension is not enabled, so no archive can be written.\nEnable ext-zip in php.ini and run this again.");
}

list($code, $out) = cyphtPkgRun(array('git', 'rev-parse', '--is-inside-work-tree'), $root);
if ($code !== 0) {
	cyphtPkgFail("Not a git repository: ".$root."\nPackaging exports the committed tree, so it needs one.");
}

list($code, $dirty) = cyphtPkgRun(array('git', 'status', '--porcelain'), $root);
if (trim($dirty) !== '' && !$options['allow-dirty']) {
	cyphtPkgFail(
		"Uncommitted changes:\n".rtrim($dirty)."\n\n".
		"Only committed files are exported, so the archive would not be what you just tested.\n".
		"Commit them, or pass --allow-dirty if you know that is what you want."
	);
}

if ($options['version'] !== '') {
	cyphtPkgCheckVersion($options['version']);
}

$dirName = cyphtPkgModuleName($root);
$work = rtrim(sys_get_temp_dir(), '/\\').'/'.$dirName.'-package-'.getmypid();
$staging = $work.'/'.$dirName;

/* Staged under the system temp directory on purpose: build.php walks up
 * looking for a Dolibarr, and finding one here would compile against a live
 * installation. Nothing above temp can be one. */
if (is_dir($work)) {
	$files->deleteRecursive($work);
}
if (!mkdir($staging, 0755, true) && !is_dir($staging)) {
	cyphtPkgFail('Could not create the staging directory at '.$staging);
}

cyphtPkgSay("== Export ==\n", $options['quiet']);

list($code, $head) = cyphtPkgRun(array('git', 'log', '-1', '--oneline'), $root);
cyphtPkgSay('HEAD '.trim($head)."\n", $options['quiet']);

$exportZip = $work.'/source.zip';
list($code, $out) = cyphtPkgRun(array('git', 'archive', '--format=zip', '--output='.$exportZip, 'HEAD'), $root);
if ($code !== 0) {
	cyphtPkgFail("git archive failed:\n".$out);
}

$zip = new ZipArchive();
if ($zip->open($exportZip) !== true) {
	cyphtPkgFail('Could not read the export at '.$exportZip);
}
$sourceCount = $zip->numFiles;
$zip->extractTo($staging);
$zip->close();
@unlink($exportZip);

cyphtPkgSay('exported '.$sourceCount." tracked files from HEAD\n", $options['quiet']);

$version = $options['version'];
if ($version === '') {
	$version = CyphtPaths::readVersionFrom($staging);
	if ($version === '') {
		cyphtPkgFail('Could not read CYPHTWEBMAIL_VERSION from the exported cyphtwebmail_version.inc.php; pass --version=X.Y.Z instead.');
	}
	cyphtPkgCheckVersion($version);
	cyphtPkgSay('version '.$version.", from cyphtwebmail_version.inc.php\n", $options['quiet']);
} else {
	cyphtPkgSay('version '.$version.", from --version\n", $options['quiet']);
}

// vendor/ is gitignored, so the export has none.
/* Copied when this tree has one, which turns a download into a file copy.
 * When it does not, nothing happens here: scripts/build.php fetches its own
 * dependencies, and it is the only thing in the module that knows how. */
if (is_dir($root.'/vendor/jason-munro/cypht')) {
	cyphtPkgSay("copying vendor/ ...\n", $options['quiet']);
	$files->copyRecursive($root.'/vendor', $staging.'/vendor');
} else {
	cyphtPkgSay("no vendor/ to copy; the build below will fetch it\n", $options['quiet']);
}

cyphtPkgSay("\n== Build ==\n", $options['quiet']);

$buildArgs = array(PHP_BINARY, 'scripts/build.php');
if ($options['quiet']) {
	$buildArgs[] = '--quiet';
}
list($code, $out) = cyphtPkgRun($buildArgs, $staging);
cyphtPkgSay($out, $options['quiet']);
if ($code !== 0) {
	cyphtPkgFail("The build failed, so nothing was packaged.");
}
if (!is_file($staging.'/public/index.php')) {
	cyphtPkgFail('The build reported success but produced no public/index.php.');
}

cyphtPkgSay("\n== Prune ==\n", $options['quiet']);

$freed = 0;
foreach (cyphtPkgPruneList() as $relative) {
	$target = $staging.'/'.$relative;
	if (!file_exists($target)) {
		continue;
	}
	if (is_dir($target)) {
		$freed += cyphtPkgDirSize($target);
		$files->deleteRecursive($target);
	} else {
		$freed += (int) @filesize($target);
		@unlink($target);
	}
}
foreach ((array) glob($staging.'/vendor/jason-munro/cypht/vendor/.*.bridged') as $marker) {
	@unlink($marker);
}
cyphtPkgSay(sprintf("removed %.1f MB the runtime never reads\n", $freed / 1048576), $options['quiet']);

cyphtPkgSay("\n== Archive ==\n", $options['quiet']);

$outDir = rtrim(str_replace('\\', '/', $options['out']), '/');
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
	cyphtPkgFail('Could not create the output directory at '.$outDir);
}

$zipName = 'module_'.$dirName.'-'.$version.'.zip';

$deployerSees = preg_replace('/\-([0-9][0-9\.]*)\.zip$/i', '', preg_replace('/module_/', '', $zipName));
if ($deployerSees !== $dirName) {
	cyphtPkgFail('Dolibarr would read "'.$zipName.'" as module "'.$deployerSees.'" and look for that directory, but the archive contains "'.$dirName.'".');
}

$zipPath = $outDir.'/'.$zipName;
@unlink($zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
	cyphtPkgFail('Could not create '.$zipPath);
}
$count = cyphtPkgAddDir($zip, $staging, $dirName);
$zip->close();

/* Read back rather than trust the write. The .env check is the one that
 * matters: the build writes only defaults now, and this makes sure a
 * regression there cannot quietly ship this machine's credentials. */
$problems = cyphtPkgVerify($zipPath, $dirName, array(
	'/vendor/twbs/' => 'a build-only asset package survived the prune',
	'/vendor/thomaspark/' => 'a build-only asset package survived the prune',
	'/.git/' => 'repository metadata was included',
));

$envEntry = $dirName.'/vendor/jason-munro/cypht/.env';
$zip = new ZipArchive();
if ($zip->open($zipPath) === true) {
	$env = $zip->getFromName($envEntry);
	$zip->close();

	if ($env !== false) {
		foreach (array('DB_NAME', 'DB_USER', 'DB_PASS') as $key) {
			if (preg_match('/^'.$key.'=(.*)$/m', $env, $found)) {
				$value = trim($found[1]);
				$placeholders = array('', 'cypht_db', 'cypht_test', 'cypht_pass');
				if (!in_array($value, $placeholders, true)) {
					$problems[] = $key.' in the shipped .env is not a placeholder; it looks like a real credential';
				}
			}
		}
	}
}

if (count($problems) > 0) {
	@unlink($zipPath);
	
	cyphtPkgFail("The archive was rejected and deleted:\n  - ".implode("\n  - ", $problems));
}

$files->deleteRecursive($work);

fwrite(STDOUT, sprintf(
	"\n%s\n%d files, %.1f MB\n",
	$zipPath,
	$count,
	filesize($zipPath) / 1048576
));

exit(0);

/**
 * @param string $dir
 * @return int Bytes
 */
function cyphtPkgDirSize($dir)
{
	$total = 0;
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($items as $item) {
		if ($item->isFile()) {
			$total += $item->getSize();
		}
	}

	return $total;
}
