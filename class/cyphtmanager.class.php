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
 * \brief       Entry point / thin facade over the Dolibarr<->Cypht glue
 *              code. This class used to hold all of that logic directly
 *              and had grown too long, so it has been split into six
 *              focused collaborators, one per subfolder of class/:
 *
 *   class/state/cyphtinstallstate.class.php    CyphtInstallState    paths, installed/built version bookkeeping
 *   class/env/cyphtenvconfig.class.php         CyphtEnvConfig       .env overrides, writing .env
 *   class/vendor/cyphtvendorbridge.class.php   CyphtVendorBridge    flat-composer-dependency vendor/ bridge, recursive copy/delete
 *   class/sso/cyphtssobridge.class.php         CyphtSsoBridge       Dolibarr SSO token + Custom_Auth/Custom_Session override + functional login
 *   class/upstream/cyphtupstreampatcher.class.php  CyphtUpstreamPatcher  patches an upstream Cypht double-require bug
 *   class/build/cyphtbuildpipeline.class.php   CyphtBuildPipeline   orchestrates composer install + config_gen.php + publish
 *
 * Every caller (admin/setup.php, admin/build/build.php,
 * admin/build/build_cancel.php, cyphtWebmailindex.php) keeps calling
 * "new CyphtManager($db)" and the same public methods exactly as before -
 * this class just delegates to the pieces above.
 */

require_once __DIR__ . '/state/cyphtinstallstate.class.php';
require_once __DIR__ . '/env/cyphtenvconfig.class.php';
require_once __DIR__ . '/vendor/cyphtvendorbridge.class.php';
require_once __DIR__ . '/sso/cyphtssobridge.class.php';
require_once __DIR__ . '/upstream/cyphtupstreampatcher.class.php';
require_once __DIR__ . '/contacts/cyphtcontactsbridge.class.php';
require_once __DIR__ . '/build/cyphtbuildpipeline.class.php';

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
	 * @var CyphtContactsBridge
	 */
	private $contactsBridge;

	/**
	 * @var CyphtBuildPipeline
	 */
	private $buildPipeline;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->paths = new CyphtInstallState();
		$this->sso = new CyphtSsoBridge($db, $this->paths);
		$this->envConfig = new CyphtEnvConfig($this->paths, $this->sso);
		$this->vendorBridge = new CyphtVendorBridge($this->paths);
		$this->upstreamPatcher = new CyphtUpstreamPatcher($this->paths);
		$this->contactsBridge = new CyphtContactsBridge($db, $this->paths);
		$this->buildPipeline = new CyphtBuildPipeline(
			$db,
			$this->paths,
			$this->envConfig,
			$this->vendorBridge,
			$this->sso,
			$this->upstreamPatcher,
			$this->contactsBridge
		);
	}

	/**
	 * Settings path for a user, used by the USER_DELETE trigger.
	 *
	 * @param string $login Dolibarr login
	 * @return string
	 */
	public function getUserSettingsPath($login)
	{
		return $this->sso->getUserSettingsPath($login);
	}

	/**
	 * @param string $login Dolibarr login
	 * @return string
	 */
	public function getLegacyUserSettingsPath($login)
	{
		return $this->sso->getLegacyUserSettingsPath($login);
	}

	// ---- CyphtInstallState ----

	/** @return string */
	public function getModuleRoot()
	{
		return $this->paths->getModuleRoot();
	}

	/** @return string */
	public function getCyphtPath()
	{
		return $this->paths->getCyphtPath();
	}

	/** @return string */
	public function getCyphtSitePath()
	{
		return $this->paths->getCyphtSitePath();
	}

	/** @return string */
	public function getPublicPath()
	{
		return $this->paths->getPublicPath();
	}

	/** @return string */
	public function getDataDir()
	{
		return $this->paths->getDataDir();
	}

	/** @return string|null */
	public function getInstalledVersion()
	{
		return $this->paths->getInstalledVersion();
	}

	/** @return string */
	public function getBuiltVersion()
	{
		return $this->paths->getBuiltVersion();
	}

	/** @return string */
	public function getLastBuildDate()
	{
		return $this->paths->getLastBuildDate();
	}

	/** @return bool */
	public function needsRebuild()
	{
		return $this->paths->needsRebuild();
	}

	/** @return bool */
	public function isPublished()
	{
		return $this->paths->isPublished();
	}

	// ---- CyphtEnvConfig ----

	/** @return array<string,string> */
	public function buildEnvOverrides()
	{
		return $this->envConfig->buildEnvOverrides();
	}

	/**
	 * @param array<string,string> $overrides
	 * @return bool
	 */
	public function writeEnvFile(array $overrides)
	{
		$result = $this->envConfig->writeEnvFile($overrides);
		$this->error = $this->envConfig->error;
		return $result;
	}

	// ---- CyphtSsoBridge ----

	/** @return string */
	public function getOrCreateSsoSecret()
	{
		return $this->sso->getOrCreateSsoSecret();
	}

	/**
	 * @param string $login
	 * @return string
	 */
	public function generateSsoLoginToken($login)
	{
		return $this->sso->generateSsoLoginToken($login);
	}

	/**
	 * @param string $login
	 * @param string $cyphtUrl
	 * @return bool
	 */
	public function performSsoLogin($login, $cyphtUrl)
	{
		$result = $this->sso->performSsoLogin($login, $cyphtUrl);
		$this->error = $this->sso->error;
		return $result;
	}

	// ---- CyphtBuildPipeline ----

	/** @return bool */
	public function publishSite()
	{
		$result = $this->buildPipeline->publishSite();
		$this->error = $this->buildPipeline->error;
		return $result;
	}

	/** @return array{success:bool,message:string} */
	public function requestCancel()
	{
		return $this->buildPipeline->requestCancel();
	}

	/**
	 * @param callable|null $onProgress
	 * @return array{success:bool,output:string,error:string}
	 */
	public function runConfigGen(callable $onProgress = null)
	{
		$result = $this->buildPipeline->runConfigGen($onProgress);
		if (empty($result['success']) && !empty($result['error'])) {
			$this->error = $result['error'];
		}
		return $result;
	}

	/**
	 * Raw NDJSON log of the most recent build attempt, same format
	 * streamed live during runConfigGen().
	 *
	 * @return string
	 */
	public function getLastBuildLog()
	{
		return $this->buildPipeline->getLastBuildLog();
	}

	/**
	 * Force-flush all output buffers to the browser. Handles multiple
	 * levels of output buffering and gzip compression. Stays directly on
	 * the facade since it's generic streaming plumbing, not tied to any
	 * one collaborator's responsibility.
	 *
	 * @return void
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
	}
}
