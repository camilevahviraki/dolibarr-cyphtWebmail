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

require_once __DIR__ . '/../state/cyphtinstallstate.class.php';
require_once __DIR__ . '/../env/cyphtenvconfig.class.php';
require_once __DIR__ . '/../vendor/cyphtvendorbridge.class.php';
require_once __DIR__ . '/../sso/cyphtssobridge.class.php';
require_once __DIR__ . '/../upstream/cyphtupstreampatcher.class.php';

/**
 * \file        class/build/cyphtbuildpipeline.class.php
 * \ingroup     cyphtWebmail
 * \brief       Runs the "Generate" pipeline: composer install, Cypht's
 *              config_gen.php, then publish. Depends on CyphtEnvConfig,
 *              CyphtVendorBridge, CyphtSsoBridge and CyphtUpstreamPatcher;
 *              see class/cyphtmanager.class.php for the facade.
 */
class CyphtBuildPipeline
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string  Last error message, if any call returned false/failure.
	 */
	public $error = '';

	/**
	 * @var CyphtInstallState
	 */
	private $paths;

	/**
	 * @var CyphtEnvConfig
	 */
	private $envConfig;

	/**
	 * @var CyphtVendorBridge
	 */
	private $vendorBridge;

	/**
	 * @var CyphtSsoBridge
	 */
	private $sso;

	/**
	 * @var CyphtUpstreamPatcher
	 */
	private $upstreamPatcher;

	/**
	 * @param DoliDB $db
	 * @param CyphtInstallState $paths
	 * @param CyphtEnvConfig $envConfig
	 * @param CyphtVendorBridge $vendorBridge
	 * @param CyphtSsoBridge $sso
	 * @param CyphtUpstreamPatcher $upstreamPatcher
	 */
	public function __construct(
		$db,
		CyphtInstallState $paths,
		CyphtEnvConfig $envConfig,
		CyphtVendorBridge $vendorBridge,
		CyphtSsoBridge $sso,
		CyphtUpstreamPatcher $upstreamPatcher
	) {
		$this->db = $db;
		$this->paths = $paths;
		$this->envConfig = $envConfig;
		$this->vendorBridge = $vendorBridge;
		$this->sso = $sso;
		$this->upstreamPatcher = $upstreamPatcher;
	}

	/**
	 * Debug log path, reset at the start of each runConfigGen() call.
	 *
	 * @return string
	 */
	private function getDebugLogPath()
	{
		return $this->paths->getModuleRoot() . '/debug.log';
	}

	/**
	 * @param string $line
	 * @return void
	 */
	private function debugLog($line)
	{
		$timestamp = date('Y-m-d H:i:s');
		@file_put_contents($this->getDebugLogPath(), "[{$timestamp}] {$line}\n", FILE_APPEND);
	}

	/**
	 * Detects a sudo password prompt or a permission/access-denied error.
	 * This module never elevates privileges; a sudo prompt would otherwise
	 * hang until the 180s timeout since stdin is closed (see runProcess()).
	 *
	 * @param string $text Newly-read stdout/stderr content to check.
	 * @return string|null Human-readable reason if recognized, null otherwise.
	 */
	private function detectPrivilegeOrCredentialPrompt($text)
	{
		if ($text === '') {
			return null;
		}

		$checks = array(
			'/\[sudo\]\s*password/i' => 'This command is asking for a sudo password. ' .
				'This web terminal will not accept or cache one. Run this command yourself ' .
				'from a system terminal as a privileged user instead.',
			'/permission denied/i' => 'Permission denied. The webserver user does not have the access ' .
				'this command needs. Fix the file/folder ownership or permissions, or run the ' .
				'command manually as a user that has them; this module will not attempt to ' .
				'elevate privileges itself.',
			'/access is denied/i' => 'Access denied. The webserver user does not have the access this ' .
				'command needs. Fix the file/folder permissions, or run the command manually as a ' .
				'user that has them; this module will not attempt to elevate privileges itself.',
		);

		foreach ($checks as $pattern => $reason) {
			if (preg_match($pattern, $text)) {
				return $reason;
			}
		}

		return null;
	}

	/**
	 * NDJSON log of the most recent build, one {t,c} object per line
	 * (same format streamed live to the browser; see runProcess()'s
	 * doc comment for what the types mean). Written incrementally as
	 * the build runs so the setup page can re-display it after a page
	 * reload, not just while the original request is open.
	 *
	 * @return string
	 */
	private function getLastBuildLogPath()
	{
		return $this->paths->getModuleRoot() . '/last_build_log.ndjson';
	}

	/**
	 * @return string Raw NDJSON content of the most recent build attempt
	 *                that actually got past the "already running" guard,
	 *                or '' if none has ever run.
	 */
	public function getLastBuildLog()
	{
		$content = @file_get_contents($this->getLastBuildLogPath());
		return $content === false ? '' : $content;
	}

	/**
	 * Run a command as a subprocess without depending on symfony/process
	 * (not guaranteed to be available to a custom module's autoloader).
	 *
	 * @param string[]      $cmd     Command + arguments, each will be escaped
	 * @param string        $cwd     Working directory to run the command in
	 * @param callable|null $onChunk Optional callback(string $chunk, string $type),
	 *                               invoked with new output as soon as it's
	 *                               read for live streaming. $type is
	 *                               always 'out', never 'err'. Composer
	 *                               writes most of its normal output to
	 *                               stderr, so 'err' is reserved for the
	 *                               caller's own exit-code-gated messages.
	 * @return array{success:bool,output:string,error:string,exitcode:int}
	 */
	private function runProcess(array $cmd, $cwd, callable $onChunk = null)
	{
		if (!function_exists('proc_open')) {
			return array(
				'success' => false,
				'output' => '',
				'error' => 'proc_open() is disabled on this server (common on shared hosting). ' .
					'Ask your host to enable proc_open/exec, or run this manually over SSH: ' .
					'cd ' . $cwd . ' && php scripts/config_gen.php',
				'exitcode' => -1,
			);
		}

		$cmdline = implode(' ', array_map('escapeshellarg', $cmd));

		// Real files, not pipes: pipe reads can block indefinitely on
		// Windows, stalling the polling loop below.
		$stdoutFile = $this->paths->getDataDir() . '/build.stdout.tmp';
		$stderrFile = $this->paths->getDataDir() . '/build.stderr.tmp';
		@unlink($stdoutFile);
		@unlink($stderrFile);

		$descriptorspec = array(
			0 => array('pipe', 'r'),
			1 => array('file', $stdoutFile, 'w'),
			2 => array('file', $stderrFile, 'w'),
		);

		$this->debugLog("STARTING: {$cmdline}");
		$this->debugLog("  cwd: {$cwd}");

		$process = proc_open($cmdline, $descriptorspec, $pipes, $cwd);
		if (!is_resource($process)) {
			$this->debugLog("  proc_open() itself returned false/failed; command never started at all.");
			return array(
				'success' => false,
				'output' => '',
				'error' => 'Unable to start process for: ' . $cmdline,
				'exitcode' => -1,
			);
		}

		$startStatus = proc_get_status($process);
		$this->debugLog("  proc_open succeeded, pid={$startStatus['pid']}, running=" . ($startStatus['running'] ? 'yes' : 'no'));

		fclose($pipes[0]);

		// Reads whatever new bytes have appeared in a growing file since
		// the last check, by byte offset; no pipe/stream involved at all.
		$readNew = function ($file, &$pos) {
			$size = @filesize($file);
			if ($size === false || $size <= $pos) {
				clearstatcache(true, $file);
				return '';
			}
			$fh = @fopen($file, 'rb');
			if ($fh === false) {
				return '';
			}
			fseek($fh, $pos);
			$chunk = stream_get_contents($fh);
			fclose($fh);
			$pos = $size;
			return $chunk === false ? '' : $chunk;
		};

		$stdoutPos = 0;
		$stderrPos = 0;
		$stdout = '';
		$stderr = '';
		$start = time();
		$timeoutSeconds = 180;
		$timedOut = false;
		$cancelled = false;
		$privilegePromptReason = null;
		$cancelFlag = $this->getCancelFlagPath();
		$lastHeartbeat = 0;

		while (true) {
			clearstatcache(true, $stdoutFile);
			clearstatcache(true, $stderrFile);
			$newOut = $readNew($stdoutFile, $stdoutPos);
			$newErr = $readNew($stderrFile, $stderrPos);
			$stdout .= $newOut;
			$stderr .= $newErr;

			if ($newOut !== '' || $newErr !== '') {
				$this->debugLog("  output received (" . strlen($newOut) . " stdout / " . strlen($newErr) . " stderr bytes)");
			}

			if ($onChunk !== null) {
				if ($newOut !== '') {
					$onChunk($newOut, 'out');
				}
				if ($newErr !== '') {
					$onChunk($newErr, 'out');
				}
			}

			$status = proc_get_status($process);
			if (!$status['running']) {
				$this->debugLog("  process exited, exitcode={$status['exitcode']}");
				break;
			}

			$elapsed = time() - $start;

			// Checked every tick so a prompt is caught immediately instead
			// of running into the generic 180s timeout below.
			$promptReason = $this->detectPrivilegeOrCredentialPrompt($newOut . $newErr);
			if ($promptReason !== null) {
				$privilegePromptReason = $promptReason;
				$this->debugLog("  privilege/credential prompt detected after {$elapsed}s, killing pid {$status['pid']}: {$promptReason}");
				if (stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
					@exec('taskkill /F /T /PID ' . ((int) $status['pid']) . ' 2>NUL');
				}
				proc_terminate($process, 9);
				break;
			}

			if ($elapsed >= $lastHeartbeat + 5) {
				$lastHeartbeat = $elapsed;
				$this->debugLog("  still running after {$elapsed}s (pid={$status['pid']}), total output so far: " . strlen($stdout) . " stdout / " . strlen($stderr) . " stderr bytes");
			}

			// Cancel button drops this flag from a separate request; this
			// loop is the one holding the process, so it does the killing.
			if (file_exists($cancelFlag)) {
				$cancelled = true;
				$this->debugLog("  cancel flag detected after {$elapsed}s, killing pid {$status['pid']}");
				@unlink($cancelFlag);
				if (stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
					@exec('taskkill /F /T /PID ' . ((int) $status['pid']) . ' 2>NUL');
				}
				proc_terminate($process, 9);
				break;
			}

			if ((time() - $start) > $timeoutSeconds) {
				$timedOut = true;
				$this->debugLog("  TIMEOUT after {$timeoutSeconds}s, killing pid {$status['pid']}");
				// taskkill /T kills the whole process tree; proc_terminate()
				// alone only kills the cmd.exe wrapper proc_open() launches
				// on Windows, leaving the real child running as an orphan.
				if (stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
					$pid = $status['pid'];
					@exec('taskkill /F /T /PID ' . ((int) $pid) . ' 2>NUL');
				}
				proc_terminate($process, 9);
				break;
			}

			usleep(150000); // 150ms, fine-grained enough without busy-looping
		}

		// Drain whatever was written in the brief window between the last
		// read above and the process actually exiting/being terminated.
		clearstatcache(true, $stdoutFile);
		clearstatcache(true, $stderrFile);
		$finalOut = $readNew($stdoutFile, $stdoutPos);
		$finalErr = $readNew($stderrFile, $stderrPos);
		$stdout .= $finalOut;
		$stderr .= $finalErr;
		if ($onChunk !== null) {
			if ($finalOut !== '') {
				$onChunk($finalOut, 'out');
			}
			if ($finalErr !== '') {
				$onChunk($finalErr, 'out');
			}
		}

		$exitCode = proc_close($process);
		@unlink($stdoutFile);
		@unlink($stderrFile);

		if ($cancelled) {
			$this->debugLog("FINISHED (cancelled): {$cmdline}");
			return array(
				'success' => false,
				'output' => $stdout,
				'error' => trim($stderr) . "\n[Cancelled by user]",
				'exitcode' => -1,
				'cancelled' => true,
			);
		}

		if ($privilegePromptReason !== null) {
			$this->debugLog("FINISHED (privilege/credential prompt): {$cmdline}");
			return array(
				'success' => false,
				'output' => $stdout,
				'error' => $privilegePromptReason,
				'exitcode' => -1,
				'privilege_prompt' => true,
			);
		}

		if ($timedOut) {
			$this->debugLog("FINISHED (timed out): {$cmdline}");
			return array(
				'success' => false,
				'output' => $stdout,
				'error' => trim($stderr) . "\n[Timed out after {$timeoutSeconds}s and was terminated]",
				'exitcode' => -1,
			);
		}

		$this->debugLog("FINISHED (exitcode={$exitCode}): {$cmdline}");
		$this->debugLog("  total stdout: " . strlen($stdout) . " bytes, stderr: " . strlen($stderr) . " bytes");

		return array(
			'success' => ($exitCode === 0),
			'output' => (string) $stdout,
			'error' => (string) $stderr,
			'exitcode' => $exitCode,
		);
	}

	/**
	 * Locate a real PHP CLI executable. PHP_BINARY is only trustworthy
	 * under a CLI-like SAPI; under mod_php it resolves to httpd.exe,
	 * not php.exe.
	 *
	 * @return string|null Path to a php executable, or null if none found.
	 */
	private function findPhpBinary()
	{
		$sapi = php_sapi_name();
		if (defined('PHP_BINARY') && PHP_BINARY && in_array($sapi, array('cli', 'cgi-fcgi', 'cgi', 'phpdbg'), true)) {
			return PHP_BINARY;
		}

		if (function_exists('exec')) {
			$isWindows = (stripos(PHP_OS, 'WIN') === 0);
			$checkCmd = $isWindows ? 'where php 2>NUL' : 'command -v php 2>/dev/null';
			$output = array();
			$exitCode = 1;
			@exec($checkCmd, $output, $exitCode);
			if ($exitCode === 0 && !empty($output[0])) {
				return trim($output[0]);
			}
		}

		// XAMPP fallback: php.exe sits next to htdocs/ but isn't always on PATH.
		if (!empty($_SERVER['DOCUMENT_ROOT'])) {
			$xamppRoot = dirname(rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/'));
			$candidate = $xamppRoot . '/php/php.exe';
			if (file_exists($candidate)) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Locate a runnable Composer command: composer.phar in the module
	 * root first, then a "composer" executable on PATH.
	 *
	 * @return string[]|null Command prefix, or null if nothing found.
	 */
	private function findComposerBinary()
	{
		$pharPath = $this->paths->getModuleRoot() . '/composer.phar';
		if (file_exists($pharPath)) {
			$phpBinary = $this->findPhpBinary();
			if ($phpBinary !== null) {
				return array($phpBinary, $pharPath);
			}
		}

		if (!function_exists('exec')) {
			return null;
		}

		$isWindows = (stripos(PHP_OS, 'WIN') === 0);
		$checkCmd = $isWindows ? 'where composer 2>NUL' : 'command -v composer 2>/dev/null';
		$output = array();
		$exitCode = 1;
		@exec($checkCmd, $output, $exitCode);

		if ($exitCode === 0 && !empty($output[0])) {
			return array(trim($output[0]));
		}

		return null;
	}

	/**
	 * Copy the built site/ folder (inside vendor/, not web-exposed) into
	 * this module's own public/ folder, which is web-exposed.
	 *
	 * @return bool
	 */
	public function publishSite()
	{
		$sitePath = $this->paths->getCyphtSitePath();
		if (!is_dir($sitePath)) {
			$this->error = 'Build succeeded but no site/ directory was produced at ' . $sitePath;
			return false;
		}

		$publicPath = $this->paths->getPublicPath();
		// Wipe the previous published copy so stale files never linger after an update.
		if (is_dir($publicPath)) {
			$this->vendorBridge->deleteRecursive($publicPath);
		}

		$this->vendorBridge->copyRecursive($sitePath, $publicPath);

		return file_exists($publicPath . '/index.php');
	}

	/**
	 * Lock file preventing two builds from running at once.
	 *
	 * @return string
	 */
	private function getLockFilePath()
	{
		return $this->paths->getDataDir() . '/build.lock';
	}

	/**
	 * Flag file signaling a running build to stop, dropped by
	 * requestCancel() and polled by runProcess()'s loop.
	 *
	 * @return string
	 */
	private function getCancelFlagPath()
	{
		return $this->paths->getDataDir() . '/build.cancel';
	}

	/**
	 * Drops the cancel flag for the running build's runProcess() loop to
	 * pick up; does not kill anything directly.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function requestCancel()
	{
		if (!file_exists($this->getLockFilePath())) {
			return array('success' => false, 'message' => 'No build appears to be running.');
		}

		file_put_contents($this->getCancelFlagPath(), (string) time());

		return array('success' => true, 'message' => 'Cancel requested. The current step will stop shortly.');
	}

	/**
	 * Entry point called by the setup page: guards against overlapping
	 * builds via a lock file, then runs the pipeline.
	 *
	 * @param callable|null $onProgress callback(string $chunk), invoked
	 *                                  live as output arrives.
	 * @return array{success:bool,output:string,error:string}
	 */
	public function runConfigGen(callable $onProgress = null)
	{
		@file_put_contents($this->getDebugLogPath(), '');
		$this->debugLog('=== runConfigGen() starting ===');
		$this->debugLog('PHP version: ' . phpversion() . ', OS: ' . PHP_OS . ', SAPI: ' . php_sapi_name());

		$lockFile = $this->getLockFilePath();

		if (file_exists($lockFile)) {
			$age = time() - (int) filemtime($lockFile);
			// 420s covers both 180s-capped steps plus the copy step;
			// older locks are treated as stale (crashed build) and ignored.
			if ($age < 420) {
				return array(
					'success' => false,
					'output' => '',
					'error' => 'A build is already running (started ' . $age . 's ago). ' .
						'Wait for it to finish rather than clicking Generate again.',
				);
			}
		}

		// Clear a stale cancel flag so it doesn't cancel this new build immediately.
		@unlink($this->getCancelFlagPath());

		// Reset only once a build is actually about to run. Unlike
		// debug.log above, a rejected "already running" click must not
		// wipe the last real build's log.
		@file_put_contents($this->getLastBuildLogPath(), '');

		file_put_contents($lockFile, (string) time());
		try {
			return $this->runConfigGenSteps($onProgress);
		} finally {
			@unlink($lockFile);
			@unlink($this->getCancelFlagPath());
		}
	}

	/**
	 * The three-step pipeline: composer install, config_gen.php (Cypht's
	 * own build step, after the .env/vendor bridge/SSO override/upstream
	 * patch are written), then publish. Always run together, one code
	 * path for first install, rebuild, or config changes. Each step is
	 * timed and streamed to $onProgress as it happens.
	 *
	 * @param callable|null $onProgress callback(string $chunk, string $type).
	 *                                  $type is 'out' for real child process
	 *                                  output (passed through from
	 *                                  runProcess(), see its doc comment),
	 *                                  'info' for step/progress headers, or
	 *                                  'err' for failure messages gated on
	 *                                  a real exit code.
	 * @return array{success:bool,output:string,error:string}
	 */
	private function runConfigGenSteps(callable $onProgress = null)
	{
		global $conf;

		$log = '';
		$emit = function ($chunk, $type = 'info') use (&$log, $onProgress) {
			$log .= $chunk;
			// Appended, not rewritten, so a build killed by the
			// timeout/cancel/failure paths below still leaves a
			// complete log up to the point it stopped.
			@file_put_contents($this->getLastBuildLogPath(), json_encode(array('t' => $type, 'c' => $chunk))."\n", FILE_APPEND);
			if ($onProgress !== null) {
				$onProgress($chunk, $type);
			}
		};

		$moduleRoot = $this->paths->getModuleRoot();
		$cyphtPath = $this->paths->getCyphtPath();

		// ---- Step 1/3: composer install ----
		$emit("== Step 1/3: composer install ==\n");
		$composerBinary = $this->findComposerBinary();
		$stepStart = microtime(true);

		if ($composerBinary !== null) {
			$installResult = $this->runProcess(
				array_merge($composerBinary, array('install', '--no-interaction', '--no-progress')),
				$moduleRoot,
				$emit
			);
			$emit(sprintf("\n[composer install finished in %.1fs]\n", microtime(true) - $stepStart));

			if (!empty($installResult['cancelled'])) {
				return array('success' => false, 'output' => $log, 'error' => 'Build cancelled.');
			}
			if (!empty($installResult['privilege_prompt'])) {
				$emit("\n" . $installResult['error'] . "\n", 'err');
				return array('success' => false, 'output' => $log, 'error' => $installResult['error']);
			}
			if (!$installResult['success']) {
				$emit("\ncomposer install failed (exit code " . $installResult['exitcode'] . ").\n", 'err');
				return array('success' => false, 'output' => $log, 'error' => 'composer install failed, see log.');
			}
		} elseif (is_dir($cyphtPath)) {
			$emit("Composer executable not found on this server, skipping, using the vendor/ already on disk as-is.\n");
		} else {
			$emit("Composer executable not found on this server, and Cypht is not present under vendor/.\n", 'err');
			return array(
				'success' => false,
				'output' => $log,
				'error' => 'Cannot install Cypht: no Composer found and vendor/jason-munro/cypht is missing. ' .
					'Install Composer on this server, or run "composer install" manually in: ' . $moduleRoot,
			);
		}

		if (!is_dir($cyphtPath)) {
			$emit("\nvendor/jason-munro/cypht still missing after composer install.\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => 'Cypht did not get installed, see log.');
		}

		// ---- Step 2/3: config_gen.php ----
		$emit("\n== Step 2/3: php scripts/config_gen.php ==\n");

		if (!$this->envConfig->writeEnvFile($this->envConfig->buildEnvOverrides())) {
			$this->error = $this->envConfig->error;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}

		if (!$this->vendorBridge->ensureCyphtVendorBridge()) {
			$this->error = $this->vendorBridge->error;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit("vendor/ bridge shim in place (Cypht installed as a flat dependency, see comment in the file).\n");

		if (!$this->sso->writeSiteAuthOverride()) {
			$this->error = $this->sso->error;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit("Dolibarr SSO auth override written to modules/site/lib.php.\n");

		if (!$this->upstreamPatcher->patchCoreFunctionsGuard()) {
			$this->error = $this->upstreamPatcher->error;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit("Patched missing hm_exists() guards in modules/core/functions.php (upstream gap, needed for SSO).\n");

		$phpBinary = $this->findPhpBinary();
		if ($phpBinary === null) {
			$emit("No usable PHP CLI executable found. PHP is running as an Apache module here, so PHP_BINARY " .
				"points at httpd.exe, not php.exe, and no 'php' was found on PATH or at the usual XAMPP location " .
				"(<xampp>/php/php.exe). Add php.exe to your system PATH, or drop a composer.phar in the module root.\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => 'No PHP CLI binary found, see log.');
		}
		$emit("Using PHP CLI: " . $phpBinary . "\n");

		$stepStart = microtime(true);
		$result = $this->runProcess(array($phpBinary, 'scripts/config_gen.php'), $cyphtPath, $emit);
		$emit(sprintf("\n[config_gen.php finished in %.1fs]\n", microtime(true) - $stepStart));

		if (!empty($result['cancelled'])) {
			return array('success' => false, 'output' => $log, 'error' => 'Build cancelled.');
		}
		if (!empty($result['privilege_prompt'])) {
			$emit("\n" . $result['error'] . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $result['error']);
		}
		if (!$result['success']) {
			$emit("\nconfig_gen.php failed (exit code " . $result['exitcode'] . ").\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => 'config_gen.php failed, see log.');
		}

		// ---- Step 3/3: publish ----
		$emit("\n== Step 3/3: publishing site/ to public/ ==\n");
		$stepStart = microtime(true);

		if (!$this->publishSite()) {
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit(sprintf("[copy finished in %.1fs]\n", microtime(true) - $stepStart));

		$version = $this->paths->getInstalledVersion();
		dolibarr_set_const($this->db, 'CYPHTWEBMAIL_LAST_BUILD', dol_now(), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'CYPHTWEBMAIL_BUILT_VERSION', $version, 'chaine', 0, '', $conf->entity);

		$emit("Published to " . $this->paths->getPublicPath() . "\nBuild complete. Cypht " . $version . " is live.\n");

		return array('success' => true, 'output' => $log, 'error' => '');
	}
}
