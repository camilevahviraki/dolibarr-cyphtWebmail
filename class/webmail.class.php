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
 * \file        class/webmail.class.php
 * \ingroup     cyphtWebmail
 * \brief       Facade over the Dolibarr<->Cypht glue code. Delegates to one
 *              collaborator per subfolder of class/:
 *
 *   class/install/paths.class.php        CyphtPaths     paths, installed/built version bookkeeping
 *   class/install/environment.class.php             CyphtEnvironment        .env overrides, writing .env
 *   class/install/vendorlayout.class.php       CyphtVendorLayout     flat-composer-dependency vendor/ bridge, recursive copy/delete
 *   class/auth/sso.class.php             CyphtSso        SSO secrets, tokens, functional login
 *   class/install/moduleinstaller.class.php     CyphtModuleInstaller  installs cypht/modules/* into the vendored Cypht
 *   class/integration/contactsource.class.php   CyphtContactSource   contacts bridge URL resolution
 *   class/install/upstreampatches.class.php  CyphtUpstreamPatches  patches upstream Cypht gaps
 *   class/install/pipeline.class.php       CyphtPipeline    composer install + config_gen.php + publish
 */

require_once __DIR__ . '/install/paths.class.php';
require_once __DIR__ . '/install/environment.class.php';
require_once __DIR__ . '/install/vendorlayout.class.php';
require_once __DIR__ . '/auth/sso.class.php';
require_once __DIR__ . '/install/upstreampatches.class.php';
require_once __DIR__ . '/integration/contactsource.class.php';
require_once __DIR__ . '/install/moduleinstaller.class.php';
require_once __DIR__ . '/install/pipeline.class.php';

class CyphtWebmail
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
	 * @var CyphtPaths
	 */
	private $paths;

	/**
	 * @var CyphtEnvironment
	 */
	private $envConfig;

	/**
	 * @var CyphtVendorLayout
	 */
	private $vendorBridge;

	/**
	 * @var CyphtSso
	 */
	private $sso;

	/**
	 * @var CyphtUpstreamPatches
	 */
	private $upstreamPatcher;

	/**
	 * @var CyphtModuleInstaller
	 */
	private $moduleInstaller;

	/**
	 * @var CyphtPipeline
	 */
	private $buildPipeline;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->paths = new CyphtPaths();
		$this->sso = new CyphtSso($db, $this->paths);
		$this->envConfig = new CyphtEnvironment($this->paths, $this->sso);
		$this->vendorBridge = new CyphtVendorLayout($this->paths);
		$this->upstreamPatcher = new CyphtUpstreamPatches($this->paths);
		$this->moduleInstaller = new CyphtModuleInstaller($this->paths);
		$this->buildPipeline = new CyphtPipeline(
			$db,
			$this->paths,
			$this->envConfig,
			$this->vendorBridge,
			$this->sso,
			$this->upstreamPatcher,
			$this->moduleInstaller
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

	// ---- CyphtPaths ----

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

	// ---- CyphtEnvironment ----

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

	// ---- CyphtSso ----

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

	// ---- CyphtPipeline ----

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
