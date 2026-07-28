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
 * \file        class/cyphtmanager.class.php
 * \ingroup     cyphtWebmail
 * \brief       Glue code between Dolibarr and the vendored Cypht webmail app.
 *
 * Responsibilities of this class (nothing more):
 *   - write Cypht's .env file from Dolibarr constants (llx_const)
 *   - shell out to Cypht's own scripts/config_gen.php to (re)build it
 *   - publish the generated site/ output somewhere the webserver can serve
 *   - track the installed vs last-built Cypht version so we know when a
 *     "composer update" of the vendored lib needs a rebuild
 *
 * This class intentionally does NOT touch Cypht's internals. Cypht is
 * treated as an opaque vendored dependency, same as Tiki treats it.
 */
class CyphtManager
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
		return $this->getModuleRoot() . '/debug.log';
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
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Absolute path to the module root (parent of this class/ directory).
	 *
	 * @return string
	 */
	public function getModuleRoot()
	{
		return dirname(__DIR__);
	}

	/**
	 * Absolute path to the vendored Cypht application (composer package root).
	 *
	 * @return string
	 */
	public function getCyphtPath()
	{
		return $this->getModuleRoot() . '/vendor/jason-munro/cypht';
	}

	/**
	 * Absolute path to the directory config_gen.php publishes its production
	 * site/ output to (inside the vendored Cypht package itself).
	 *
	 * @return string
	 */
	public function getCyphtSitePath()
	{
		return $this->getCyphtPath() . '/site';
	}

	/**
	 * Absolute path to the module's own public/ directory, which is the
	 * folder we copy the built Cypht site/ into so it sits somewhere the
	 * webserver can serve directly (vendor/ should never be web-exposed).
	 *
	 * @return string
	 */
	public function getPublicPath()
	{
		return $this->getModuleRoot() . '/public';
	}

	/**
	 * Dolibarr-managed data directory for this module (outside web root,
	 * created at module activation via $this->dirs in the descriptor).
	 * Ensures the "users" and "attachments" subfolders Cypht needs exist.
	 *
	 * @return string
	 */
	public function getDataDir()
	{
		$dir = DOL_DATA_ROOT . '/cyphtWebmail';

		require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
		dol_mkdir($dir . '/users');
		dol_mkdir($dir . '/attachments');

		return $dir;
	}

	/**
	 * Read jason-munro/cypht's installed version straight from Composer's
	 * own installed.json, so this always reflects whatever is actually on
	 * disk (post composer update) rather than something we cached earlier.
	 *
	 * @return string|null  Version string (e.g. "2.11.1") or null if unknown
	 */
	public function getInstalledVersion()
	{
		$installedJson = $this->getModuleRoot() . '/vendor/composer/installed.json';
		if (!file_exists($installedJson)) {
			return null;
		}

		$data = json_decode(file_get_contents($installedJson), true);
		if (!is_array($data)) {
			return null;
		}
		// Composer 2 wraps the package list in a "packages" key; composer 1 was a flat array.
		$packages = isset($data['packages']) ? $data['packages'] : $data;

		foreach ($packages as $pkg) {
			if (isset($pkg['name']) && $pkg['name'] === 'jason-munro/cypht') {
				return ltrim($pkg['version'], 'v');
			}
		}

		return null;
	}

	/**
	 * Version that was actually built last time the button was clicked
	 * (stored in llx_const after a successful runConfigGen()).
	 *
	 * @return string
	 */
	public function getBuiltVersion()
	{
		return getDolGlobalString('CYPHTWEBMAIL_BUILT_VERSION', '');
	}

	/**
	 * Timestamp of the last successful build, or empty string if never built.
	 *
	 * @return string
	 */
	public function getLastBuildDate()
	{
		return getDolGlobalString('CYPHTWEBMAIL_LAST_BUILD', '');
	}

	/**
	 * True if the vendored Cypht version on disk differs from the version
	 * we last generated a config for (e.g. after "composer update" pulled
	 * a newer jason-munro/cypht release into vendor/).
	 *
	 * @return bool
	 */
	public function needsRebuild()
	{
		$installed = $this->getInstalledVersion();
		if ($installed === null) {
			return false; // Cypht isn't even installed, nothing to rebuild
		}
		return ($installed !== $this->getBuiltVersion());
	}

	/**
	 * True if a build has ever succeeded and its output was published.
	 *
	 * @return bool
	 */
	public function isPublished()
	{
		return file_exists($this->getPublicPath() . '/index.php');
	}

	/**
	 * Build the list of .env overrides derived from Dolibarr's own config
	 * (llx_const, set via the admin/setup.php form) plus fixed defaults
	 * that make sense for running inside Dolibarr (no separate Cypht DB,
	 * no Redis/Memcached assumed present).
	 *
	 * @return array<string,string>
	 */
	public function buildEnvOverrides()
	{
		$dataDir = $this->getDataDir();

		return array(
			'SESSION_TYPE'     => 'PHP',
			'AUTH_TYPE'        => 'IMAP',
			'IMAP_AUTH_NAME'   => getDolGlobalString('CYPHTWEBMAIL_IMAP_NAME', 'Webmail'),
			'IMAP_AUTH_SERVER' => getDolGlobalString('CYPHTWEBMAIL_IMAP_SERVER', 'localhost'),
			'IMAP_AUTH_PORT'   => getDolGlobalString('CYPHTWEBMAIL_IMAP_PORT', '993'),
			'IMAP_AUTH_TLS'    => getDolGlobalString('CYPHTWEBMAIL_IMAP_TLS', 'true'),
			'USER_CONFIG_TYPE' => 'file',
			'USER_SETTINGS_DIR' => $dataDir . '/users',
			'ATTACHMENT_DIR'   => $dataDir . '/attachments',
			'ENABLE_REDIS'     => 'false',
			'ENABLE_MEMCACHED' => 'false',
			'ENABLE_DEBUG'     => 'false',
			'DEFAULT_LANGUAGE' => 'en',
			// Cypht's own .env.example ships the full module list, which
			// includes "themes" - that makes config_gen.php process every
			// Bootswatch theme variant (the single biggest chunk of the
			// 173MB vendor/ tree), massively slowing the build for
			// something this POC doesn't need yet. Trimmed to the minimum
			// needed to prove IMAP webmail works end to end; add modules
			// back here deliberately once the core flow is proven.
			'CYPHT_MODULES'    => 'core,imap,smtp,account,idle_timer,nux,profiles,imap_folders,tags,history',
		);
	}

	/**
	 * Write (or update) Cypht's .env file with the given overrides. Starts
	 * from the existing .env if present, otherwise from .env.example so we
	 * never lose the many settings we don't manage ourselves.
	 *
	 * @param array<string,string> $overrides Key/value pairs to force
	 * @return bool True on success
	 */
	public function writeEnvFile(array $overrides)
	{
		$cyphtPath = $this->getCyphtPath();
		$envFile = $cyphtPath . '/.env';
		$source = file_exists($envFile) ? $envFile : $cyphtPath . '/.env.example';

		if (!file_exists($source)) {
			$this->error = 'Neither .env nor .env.example found in ' . $cyphtPath . '. Is Cypht actually installed under vendor/?';
			return false;
		}

		$lines = file($source, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			$this->error = 'Could not read ' . $source;
			return false;
		}

		$seen = array();
		foreach ($lines as $i => $line) {
			if (preg_match('/^([A-Z0-9_]+)=/', $line, $m)) {
				$key = $m[1];
				if (array_key_exists($key, $overrides)) {
					$lines[$i] = $key . '=' . $overrides[$key];
					$seen[$key] = true;
				}
			}
		}
		foreach ($overrides as $key => $value) {
			if (empty($seen[$key])) {
				$lines[] = $key . '=' . $value;
			}
		}

		$result = file_put_contents($envFile, implode("\n", $lines) . "\n");
		if ($result === false) {
			$this->error = 'Could not write ' . $envFile . ' (permissions?)';
			return false;
		}

		return true;
	}

	/**
	 * Run a command as a subprocess without depending on symfony/process
	 * (not guaranteed to be available to a custom module's autoloader).
	 *
	 * @param string[]      $cmd     Command + arguments, each will be escaped
	 * @param string        $cwd     Working directory to run the command in
	 * @param callable|null $onChunk Optional callback(string $chunk), invoked
	 *                               with new stdout/stderr content as soon as
	 *                               it's read - lets the caller stream output
	 *                               to the browser live instead of waiting
	 *                               for the whole process to finish. Also
	 *                               what keeps the HTTP connection from going
	 *                               silent long enough for Apache's own
	 *                               request timeout to drop it.
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
		$stdoutFile = $this->getDataDir() . '/build.stdout.tmp';
		$stderrFile = $this->getDataDir() . '/build.stderr.tmp';
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

			if ($onChunk !== null && ($newOut !== '' || $newErr !== '')) {
				$onChunk($newOut . $newErr);
			}

			$status = proc_get_status($process);
			if (!$status['running']) {
				$this->debugLog("  process exited, exitcode={$status['exitcode']}");
				break;
			}

			$elapsed = time() - $start;
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
		if ($onChunk !== null && ($finalOut !== '' || $finalErr !== '')) {
			$onChunk($finalOut . $finalErr);
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
		$pharPath = $this->getModuleRoot() . '/composer.phar';
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
	 * Recursively copy a directory (Cypht's site/ output isn't huge, this
	 * is only run when the button is clicked, not on every page load).
	 *
	 * @param string $src
	 * @param string $dst
	 * @return void
	 */
	private function copyRecursive($src, $dst)
	{
		if (!is_dir($dst)) {
			mkdir($dst, 0755, true);
		}
		$items = scandir($src);
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$srcPath = $src . '/' . $item;
			$dstPath = $dst . '/' . $item;
			if (is_dir($srcPath)) {
				$this->copyRecursive($srcPath, $dstPath);
			} else {
				copy($srcPath, $dstPath);
			}
		}
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
		$sitePath = $this->getCyphtSitePath();
		if (!is_dir($sitePath)) {
			$this->error = 'Build succeeded but no site/ directory was produced at ' . $sitePath;
			return false;
		}

		$publicPath = $this->getPublicPath();
		// Wipe the previous published copy so stale files never linger after an update.
		if (is_dir($publicPath)) {
			$this->deleteRecursive($publicPath);
		}

		$this->copyRecursive($sitePath, $publicPath);

		return file_exists($publicPath . '/index.php');
	}

	/**
	 * @param string $dir
	 * @return void
	 */
	private function deleteRecursive($dir)
	{
		$items = scandir($dir);
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			if (is_dir($path)) {
				$this->deleteRecursive($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	}

	/**
	 * Cypht's own scripts (config_gen.php, and the published index.php at
	 * runtime) expect a *nested* vendor/autoload.php inside Cypht's own
	 * package directory - they're written for a standalone Cypht checkout
	 * with its own top-level composer install. We install Cypht as a flat
	 * dependency of this module instead (the same approach Tiki uses), so
	 * Composer resolves all of Cypht's own dependencies into *this
	 * module's* shared vendor/ - there is no nested vendor/ inside
	 * vendor/jason-munro/cypht/.
	 *
	 * This creates a tiny shim at the exact path Cypht expects, forwarding
	 * to the real shared autoloader. It must be re-created on every build:
	 * Composer fully re-extracts a package's directory whenever its locked
	 * version changes, which would silently delete anything dropped inside
	 * it by a previous build.
	 *
	 * @return bool
	 */
	private function ensureCyphtVendorBridge()
	{
		$bridgeDir = $this->getCyphtPath() . '/vendor';
		$bridgeFile = $bridgeDir . '/autoload.php';

		if (!is_dir($bridgeDir) && !mkdir($bridgeDir, 0755, true) && !is_dir($bridgeDir)) {
			$this->error = 'Could not create ' . $bridgeDir;
			return false;
		}

		$content = "<?php\n" .
			"// Auto-generated by CyphtManager::ensureCyphtVendorBridge() - do not edit,\n" .
			"// this file is recreated on every build and any manual changes will be lost.\n" .
			"//\n" .
			"// Cypht is installed as a flat Composer dependency of the cyphtWebmail module,\n" .
			"// so its own dependencies live in the module's shared vendor/, not a nested\n" .
			"// one here. This forwards Cypht's own scripts (config_gen.php, index.php) to\n" .
			"// that shared autoloader.\n" .
			"return require dirname(__DIR__, 3).'/autoload.php';\n";

		if (file_put_contents($bridgeFile, $content) === false) {
			$this->error = 'Could not write ' . $bridgeFile;
			return false;
		}

		return true;
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
		return $this->getDataDir() . '/build.lock';
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
		return $this->getDataDir() . '/build.cancel';
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
	 *   2. config_gen.php     - Cypht's own build step
	 *   3. publish site/      - copy the build output where the webserver
	 *                           can reach it
	 *
	 * Every step's output is both streamed to $onProgress as it happens
	 * (if given) and accumulated into a single log string, timed
	 * individually, so a slow or stuck run can be pinned to a specific
	 * step instead of just "the button took a while".
	 *
	 * @param callable|null $onProgress callback(string $chunk)
	 * @return array{success:bool,output:string,error:string}
	 */
	private function runConfigGenSteps(callable $onProgress = null)
	{
		global $conf;

		$log = '';
		$emit = function ($chunk) use (&$log, $onProgress) {
			$log .= $chunk;
			if ($onProgress !== null) {
				$onProgress($chunk);
			}
		};

		$moduleRoot = $this->getModuleRoot();
		$cyphtPath = $this->getCyphtPath();

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
			if (!$installResult['success']) {
				$emit("\ncomposer install failed (exit code " . $installResult['exitcode'] . ").\n");
				return array('success' => false, 'output' => $log, 'error' => 'composer install failed, see log.');
			}
		} elseif (is_dir($cyphtPath)) {
			$emit("Composer executable not found on this server - skipping, using the vendor/ already on disk as-is.\n");
		} else {
			$emit("Composer executable not found on this server, and Cypht is not present under vendor/.\n");
			return array(
				'success' => false,
				'output' => $log,
				'error' => 'Cannot install Cypht: no Composer found and vendor/jason-munro/cypht is missing. ' .
					'Install Composer on this server, or run "composer install" manually in: ' . $moduleRoot,
			);
		}

		if (!is_dir($cyphtPath)) {
			$emit("\nvendor/jason-munro/cypht still missing after composer install.\n");
			return array('success' => false, 'output' => $log, 'error' => 'Cypht did not get installed, see log.');
		}

		// ---- Step 2/3: config_gen.php ----
		$emit("\n== Step 2/3: php scripts/config_gen.php ==\n");

		if (!$this->writeEnvFile($this->buildEnvOverrides())) {
			$emit($this->error . "\n");
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}

		if (!$this->ensureCyphtVendorBridge()) {
			$emit($this->error . "\n");
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit("vendor/ bridge shim in place (Cypht installed as a flat dependency, see comment in the file).\n");

		$phpBinary = $this->findPhpBinary();
		if ($phpBinary === null) {
			$emit("No usable PHP CLI executable found. PHP is running as an Apache module here, so PHP_BINARY " .
				"points at httpd.exe, not php.exe - and no 'php' was found on PATH or at the usual XAMPP location " .
				"(<xampp>/php/php.exe). Add php.exe to your system PATH, or drop a composer.phar in the module root.\n");
			return array('success' => false, 'output' => $log, 'error' => 'No PHP CLI binary found, see log.');
		}
		$emit("Using PHP CLI: " . $phpBinary . "\n");

		$stepStart = microtime(true);
		$result = $this->runProcess(array($phpBinary, 'scripts/config_gen.php'), $cyphtPath, $emit);
		$emit(sprintf("\n[config_gen.php finished in %.1fs]\n", microtime(true) - $stepStart));

		if (!empty($result['cancelled'])) {
			return array('success' => false, 'output' => $log, 'error' => 'Build cancelled.');
		}
		if (!$result['success']) {
			$emit("\nconfig_gen.php failed (exit code " . $result['exitcode'] . ").\n");
			return array('success' => false, 'output' => $log, 'error' => 'config_gen.php failed, see log.');
		}

		// ---- Step 3/3: publish ----
		$emit("\n== Step 3/3: publishing site/ to public/ ==\n");
		$stepStart = microtime(true);

		if (!$this->publishSite()) {
			$emit($this->error . "\n");
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit(sprintf("[copy finished in %.1fs]\n", microtime(true) - $stepStart));

		$version = $this->getInstalledVersion();
		dolibarr_set_const($this->db, 'CYPHTWEBMAIL_LAST_BUILD', dol_now(), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'CYPHTWEBMAIL_BUILT_VERSION', $version, 'chaine', 0, '', $conf->entity);

		$emit("Published to " . $this->getPublicPath() . "\nBuild complete - Cypht " . $version . " is live.\n");

		return array('success' => true, 'output' => $log, 'error' => '');
	}

	/**
     * Force-flush all output buffers to the browser
     * Handles multiple levels of output buffering and gzip compression
     */
    public function cyphtwebmail_flush_now()
    {
        // Disable compression if it's causing buffering issues
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 'Off');
        }
        
        // Flush all PHP output buffers
        while (ob_get_level() > 0) {
            $status = ob_get_status();
            if ($status && isset($status['name']) && $status['name'] === 'ob_gzhandler') {
                // Don't try to flush gzip handler, it breaks
                break;
            }
            ob_end_flush();
        }
        
        // Flush the web server's buffer
        flush();
        
        // If using FastCGI, this helps
        if (function_exists('fastcgi_finish_request')) {
            // Only call if we're done with output
            // fastcgi_finish_request();
        }
    }

	// /**
	//  * Push whatever has been printed so far out to the browser immediately,
	//  * bypassing both PHP's own output buffer (if any is active) and Apache's.
	//  * Used before and repeatedly during the build so the connection never goes
	//  * silent long enough for Apache's own request timeout to drop it, and so
	//  * the page shows real progress instead of looking frozen.
	//  *
	//  * @return void
	//  */
	// public function cyphtwebmail_flush_now()
	// {
	// 	if (ob_get_level() > 0) {
	// 		@ob_flush();
	// 	}
	// 	@flush();
	// }
}
