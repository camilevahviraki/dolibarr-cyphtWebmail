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
 * \brief       Orchestrates the actual "Generate" button pipeline: shell
 *              out to composer + Cypht's own config_gen.php, then publish
 *              the output. Extracted out of CyphtManager, which had grown
 *              too large - see class/cyphtmanager.class.php for the facade
 *              that wires this together with its siblings.
 *
 * This is the one collaborator that needs all the others: env overrides
 * (CyphtEnvConfig), the flat-dependency vendor shim (CyphtVendorBridge),
 * the SSO auth override (CyphtSsoBridge), and the upstream guard patch
 * (CyphtUpstreamPatcher) all have to run as part of the same build.
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
	 * Debug log living *inside the module folder itself* (not under
	 * DOL_DATA_ROOT, which may sit outside whatever directory is being
	 * inspected) so its content is always directly readable without going
	 * through the browser or asking someone to relay what they see on
	 * screen. Every runProcess() call appends the exact command run, its
	 * PID, and periodic "still running" heartbeats while it works, plus
	 * how it ended. Overwritten at the start of each runConfigGen() call
	 * so it only ever reflects the most recent attempt.
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
	 * Recognizes the two realistic ways a child process (composer,
	 * config_gen.php) can end up stuck on something this web terminal
	 * deliberately does not and should not provide: a sudo password
	 * prompt, or a plain permission/ownership error. stdin is already
	 * closed immediately after proc_open() (see the comment there),
	 * which makes most *ordinary* prompts fail fast with their own error
	 * instead of hanging - but `sudo` in particular is known to read the
	 * password straight from the controlling terminal rather than stdin
	 * on some configurations, which stdin being closed does nothing to
	 * stop, and would otherwise hang until runProcess()'s own 180s
	 * timeout finally kills it with a generic, unhelpful "[Timed out]".
	 *
	 * Two other patterns (SSH key passphrase, git HTTPS credential
	 * prompts) were removed after review: both would only ever come from
	 * `composer install`, which already runs with --no-interaction and
	 * so fails fast with its own error instead of actually printing
	 * either prompt - the patterns would never have matched anything
	 * real. Permission/access-denied earns its place for a different
	 * reason than sudo does: it's not a hang risk (it already fails
	 * fast on its own), it's just the single most common real-world
	 * Composer failure across shared hosting and VPS Linux deployments
	 * (web server user vs. file owner mismatches), so it's worth a
	 * clear reported reason instead of a bare "exit code 1".
	 *
	 * Deliberately does not try to answer or elevate either case itself
	 * - this module runs as whatever user the webserver runs as, and the
	 * fix for both is a one-time setup step outside the browser (fix
	 * ownership/permissions, or grant the webserver user what it needs),
	 * not a prompt this UI should be answering.
	 *
	 * @param string $text Newly-read stdout/stderr content to check.
	 * @return string|null Human-readable reason if a prompt/permission
	 *                      issue was recognized, null if $text looks like
	 *                      ordinary output.
	 */
	private function detectPrivilegeOrCredentialPrompt($text)
	{
		if ($text === '') {
			return null;
		}

		$checks = array(
			// sudo specifically prompting for a password - the one real
			// hang risk in this list, see the doc comment above.
			'/\[sudo\]\s*password/i' => 'This command is asking for a sudo password. ' .
				'This web terminal will not accept or cache one - run this command yourself ' .
				'from a system terminal as a privileged user instead.',
			// Plain permission/ownership errors - nothing to prompt for
			// or hang on, just the most common real-world failure worth
			// naming clearly instead of a bare exit-code message.
			'/permission denied/i' => 'Permission denied - the webserver user does not have the access ' .
				'this command needs. Fix the file/folder ownership or permissions, or run the ' .
				'command manually as a user that has them; this module will not attempt to ' .
				'elevate privileges itself.',
			'/access is denied/i' => 'Access denied - the webserver user does not have the access this ' .
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
	 * Same NDJSON format streamed live to the browser (one {t,c} JSON
	 * object per line - see runProcess()'s doc comment for what the
	 * types mean), just also written to disk as it happens so the setup
	 * page can show the last build's colored log again on a fresh page
	 * load, not just while the original streaming request is still open.
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
	 *                               invoked with new stdout/stderr content as
	 *                               soon as it's read - lets the caller stream
	 *                               output to the browser live instead of
	 *                               waiting for the whole process to finish.
	 *                               Also what keeps the HTTP connection from
	 *                               going silent long enough for Apache's own
	 *                               request timeout to drop it. $type is
	 *                               always 'out' here, for both stdout and
	 *                               stderr content - deliberately NOT tagged
	 *                               'err' just because it came from the
	 *                               stderr stream. Composer in particular
	 *                               writes almost all of its normal,
	 *                               successful progress output to stderr by
	 *                               convention (confirmed live: a clean
	 *                               "composer install" with exit code 0
	 *                               produced 0 stdout bytes and 338 stderr
	 *                               bytes) - coloring by raw stream would
	 *                               paint a fully successful run red. 'err'
	 *                               is reserved for the caller's own
	 *                               synthetic failure messages below, which
	 *                               are gated on the real exit code instead.
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

		// stdout/stderr are redirected to real files instead of pipes. On
		// Windows, stream_set_blocking() does not reliably apply to
		// proc_open()'s pipe handles - reads on them can still block
		// indefinitely regardless of the non-blocking flag. That was the
		// actual cause of config_gen.php appearing to hang forever with
		// debug.log stopping right after "proc_open succeeded": the very
		// first stream_get_contents() call on stdout blocked waiting for
		// data, so the loop below never ran a second iteration - no
		// heartbeat, no timeout check, no cancel check, nothing. Plain
		// file reads (filesize()/fread()) are not subject to this
		// limitation, so we poll the files on disk instead.
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
			$this->debugLog("  proc_open() itself returned false/failed - command never started at all.");
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
		// the last check, by byte offset - no pipe/stream involved at all.
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

			// Checked on every poll tick (not just once) since a prompt
			// can appear well after the process starts, e.g. partway
			// through resolving a VCS dependency. Caught here, rather
			// than left to run into the 180s timeout below, so the user
			// gets a specific reason instead of a generic "[Timed out]"
			// - see detectPrivilegeOrCredentialPrompt()'s own comment for
			// why this module never tries to actually answer the prompt.
			$promptReason = $this->detectPrivilegeOrCredentialPrompt($newOut . $newErr);
			if ($promptReason !== null) {
				$privilegePromptReason = $promptReason;
				$this->debugLog("  privilege/credential prompt detected after {$elapsed}s - killing pid {$status['pid']}: {$promptReason}");
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

			// The Cancel button (a separate request) drops this flag file
			// rather than trying to kill the process itself - this request
			// is the one that actually knows the PID/resource, so it does
			// the killing, it just needs telling to.
			if (file_exists($cancelFlag)) {
				$cancelled = true;
				$this->debugLog("  cancel flag detected after {$elapsed}s - killing pid {$status['pid']}");
				@unlink($cancelFlag);
				if (stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
					@exec('taskkill /F /T /PID ' . ((int) $status['pid']) . ' 2>NUL');
				}
				proc_terminate($process, 9);
				break;
			}

			if ((time() - $start) > $timeoutSeconds) {
				$timedOut = true;
				$this->debugLog("  TIMEOUT after {$timeoutSeconds}s - killing pid {$status['pid']}");
				// On Windows, proc_open() launches the command through cmd.exe,
				// so proc_terminate() only kills that cmd.exe wrapper - the
				// actual php.exe (or composer) child keeps running as an
				// orphan, invisible to this script but still eating CPU/IO.
				// taskkill /T kills the whole process tree, not just the PID
				// PHP knows about.
				if (stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
					$pid = $status['pid'];
					@exec('taskkill /F /T /PID ' . ((int) $pid) . ' 2>NUL');
				}
				proc_terminate($process, 9);
				break;
			}

			usleep(150000); // 150ms - fine-grained enough without busy-looping
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
	 * Locate a real PHP CLI executable. PHP_BINARY is only trustworthy when
	 * the current request itself is running under a CLI-like SAPI - when
	 * PHP is running as an Apache module (mod_php, the XAMPP default),
	 * PHP_BINARY resolves to httpd.exe itself, not php.exe, and shelling
	 * out to "PHP_BINARY scripts/config_gen.php" ends up asking Apache to
	 * reconfigure itself instead of running the script.
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

		// XAMPP-specific fallback: php.exe normally sits as a sibling of
		// htdocs/ (e.g. C:\xampp\php\php.exe), and XAMPP does not always
		// add it to PATH, so "where php" above can legitimately fail.
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
	 * Locate a runnable Composer command on this server. Checks for a
	 * composer.phar sitting in the module root first (the most portable
	 * option, no PATH dependency), then falls back to a "composer"
	 * executable on PATH.
	 *
	 * @return string[]|null Command prefix (e.g. [php, composer.phar] or
	 *                        [composer]) to prepend arguments to, or null
	 *                        if nothing usable was found.
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
	 * Publish the freshly built site/ folder (inside vendor/, never web
	 * exposed) into this module's own public/ folder (inside custom/cyphtWebmail,
	 * which the webserver already serves since the whole module lives under
	 * htdocs/custom/).
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
	 * Path to the lock file used to stop two builds running at once (e.g.
	 * a second click on the Generate button while the first is still
	 * running - the page gives no visual feedback while it's working, so
	 * this is an easy accident, and each overlapping build is a full
	 * composer install + config_gen.php + asset copy competing for the
	 * same CPU/disk, which is exactly what makes the whole server feel
	 * like it froze).
	 *
	 * @return string
	 */
	private function getLockFilePath()
	{
		return $this->paths->getDataDir() . '/build.lock';
	}

	/**
	 * Path to the flag file used to ask a currently-running build to stop.
	 * Dropped by requestCancel() (called from the Cancel button's AJAX
	 * request) and polled by runProcess()'s loop in the *other*, actually
	 * running request - that request is the one holding the real process
	 * resource, so it has to be the one to kill it; this file is just the
	 * signal between the two requests.
	 *
	 * @return string
	 */
	private function getCancelFlagPath()
	{
		return $this->paths->getDataDir() . '/build.cancel';
	}

	/**
	 * Called from the Cancel button. Does not kill anything itself - it
	 * can't, it has no handle on the other request's process - it just
	 * drops the flag that request's runProcess() loop checks every ~150ms.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function requestCancel()
	{
		if (!file_exists($this->getLockFilePath())) {
			return array('success' => false, 'message' => 'No build appears to be running.');
		}

		file_put_contents($this->getCancelFlagPath(), (string) time());

		return array('success' => true, 'message' => 'Cancel requested - the current step will stop shortly.');
	}

	/**
	 * The single entry point the setup page button calls: guards against
	 * overlapping builds via a lock file, then runs the real pipeline.
	 *
	 * @param callable|null $onProgress callback(string $chunk), invoked
	 *                                  live as output arrives so the caller
	 *                                  can stream it to the browser instead
	 *                                  of waiting for the whole build.
	 * @return array{success:bool,output:string,error:string}
	 */
	public function runConfigGen(callable $onProgress = null)
	{
		// Reset so this file only ever reflects the most recent attempt -
		// readable directly from the module folder without needing anyone
		// to relay what happened.
		@file_put_contents($this->getDebugLogPath(), '');
		$this->debugLog('=== runConfigGen() starting ===');
		$this->debugLog('PHP version: ' . phpversion() . ', OS: ' . PHP_OS . ', SAPI: ' . php_sapi_name());

		$lockFile = $this->getLockFilePath();

		if (file_exists($lockFile)) {
			$age = time() - (int) filemtime($lockFile);
			// Generous ceiling: covers composer install + config_gen.php,
			// each individually capped at 180s by runProcess(), plus the
			// file copy step. Anything older than this is a stale lock
			// left behind by a build that crashed without cleaning up
			// (e.g. a PHP fatal error outside our own try/finally), not
			// one that's still legitimately running - so we let it proceed.
			if ($age < 420) {
				return array(
					'success' => false,
					'output' => '',
					'error' => 'A build is already running (started ' . $age . 's ago). ' .
						'Wait for it to finish rather than clicking Generate again.',
				);
			}
		}

		// Clear out any stale cancel flag from a previous run before we
		// start - otherwise a leftover flag would cancel this new build
		// within the first poll tick.
		@unlink($this->getCancelFlagPath());

		// Reset only here, once we've actually committed to running a
		// real build - not unconditionally at the top of this method like
		// debug.log is, since that would wipe the last real build's
		// persisted log every time someone clicks Generate while one is
		// already running and just gets rejected below.
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
	 * The actual three-step pipeline, always run together so there is one
	 * code path for "first install", "Cypht was updated via composer,
	 * rebuild it", and "I just changed the IMAP settings":
	 *
	 *   1. composer install  - make sure vendor/ actually matches
	 *                           composer.json/composer.lock (installs
	 *                           Cypht the first time, pulls in updates
	 *                           any other time)
	 *   2. config_gen.php     - Cypht's own build step, after this class's
	 *                           collaborators have written the .env, the
	 *                           vendor bridge, the SSO auth override, and
	 *                           the upstream guard patch
	 *   3. publish site/      - copy the build output where the webserver
	 *                           can reach it
	 *
	 * Every step's output is both streamed to $onProgress as it happens
	 * (if given) and accumulated into a single log string, timed
	 * individually, so a slow or stuck run can be pinned to a specific
	 * step instead of just "the button took a while".
	 *
	 * @param callable|null $onProgress callback(string $chunk, string $type).
	 *                                  $type is 'out' for real child process
	 *                                  output (stdout and stderr alike,
	 *                                  passed straight through from
	 *                                  runProcess() - see its own doc
	 *                                  comment for why stderr isn't tagged
	 *                                  'err' just for being stderr), 'info'
	 *                                  for this method's own synthetic
	 *                                  step/progress headers, or 'err' for
	 *                                  this method's own synthetic failure
	 *                                  messages - those are the only ones
	 *                                  gated on a real exit code, so they're
	 *                                  the only ones that should read as an
	 *                                  actual error to the caller.
	 * @return array{success:bool,output:string,error:string}
	 */
	private function runConfigGenSteps(callable $onProgress = null)
	{
		global $conf;

		$log = '';
		$emit = function ($chunk, $type = 'info') use (&$log, $onProgress) {
			$log .= $chunk;
			// $this is available here without a "use" clause - closures
			// created inside a method automatically inherit it in PHP.
			// Appended (not rewritten) each call so a build that gets
			// killed by the timeout/cancel/crash paths below still
			// leaves a complete, readable log up to the point it stopped,
			// same reasoning as debugLog()'s own incremental writes.
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
			$emit("Composer executable not found on this server - skipping, using the vendor/ already on disk as-is.\n");
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
				"points at httpd.exe, not php.exe - and no 'php' was found on PATH or at the usual XAMPP location " .
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

		$emit("Published to " . $this->paths->getPublicPath() . "\nBuild complete - Cypht " . $version . " is live.\n");

		return array('success' => true, 'output' => $log, 'error' => '');
	}
}
