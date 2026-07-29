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
require_once __DIR__ . '/../sso/cyphtssobridge.class.php';

/**
 * \file        class/env/cyphtenvconfig.class.php
 * \ingroup     cyphtWebmail
 * \brief       Builds and writes Cypht's .env file from Dolibarr constants.
 *              Extracted out of CyphtManager, which had grown too large -
 *              see class/cyphtmanager.class.php for the facade that wires
 *              this together with its siblings.
 */
class CyphtEnvConfig
{
	/**
	 * @var string  Last error message, if any call returned false/failure.
	 */
	public $error = '';

	/**
	 * @var CyphtInstallState
	 */
	private $paths;

	/**
	 * @var CyphtSsoBridge
	 */
	private $sso;

	/**
	 * @param CyphtInstallState $paths
	 * @param CyphtSsoBridge $sso
	 */
	public function __construct(CyphtInstallState $paths, CyphtSsoBridge $sso)
	{
		$this->paths = $paths;
		$this->sso = $sso;
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
		$dataDir = $this->paths->getDataDir();

		return array(
			'SESSION_TYPE'     => 'custom',
			'AUTH_TYPE'        => 'custom',
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
			// "account" must stay in this list: it's the module behind
			// Cypht's own "Add an E-mail Account"/Servers settings page,
			// which is how each user configures their real IMAP mailbox
			// after SSO logs them in (same decoupled pattern Tiki uses).
			// "api_login" must also stay in: it's what performSsoLogin()
			// (CyphtSsoBridge) actually calls.
			// "themes" must also stay in (added for Cypht dev-master):
			// it's the first-party module that now ships the Bootswatch
			// theme CSS packs and injects the <link> tag for the active
			// one on every page (replaces the old external
			// thomaspark/bootswatch package our vendor bridge targeted).
			// Without it, requests for modules/themes/assets/*/css/*.css
			// have no handler and crash instead of loading, which is why
			// the whole app rendered unstyled.
			'CYPHT_MODULES'    => 'core,contacts,imap,smtp,api_login,account,nux,developer,history,saved_searches,advanced_search,profiles,inline_message,imap_folders,keyboard_shortcuts,site,dynamic_login,sievefilters,themes',
			'DISABLE_FINGERPRINT' => 'true',
			'DISABLE_EMPTY_SUPERGLOBALS' => 'true',
			'SSO_SHARED_SECRET' => $this->sso->getOrCreateSsoSecret(),
			'DISABLE_OPEN_BASE_DIR' => 'true',
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
		$cyphtPath = $this->paths->getCyphtPath();
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
}
