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
require_once __DIR__ . '/../contacts/cyphtcontactsbridge.class.php';

/**
 * \file        class/env/cyphtenvconfig.class.php
 * \ingroup     cyphtWebmail
 * \brief       Builds and writes Cypht's .env file from Dolibarr constants.
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
		global $conf;

		$dataDir = $this->paths->getDataDir();

		// Cypht stores per-user settings in Dolibarr's own database. Taken from
		// $conf->db, which is already decrypted when the password is encrypted
		// in conf.php.
		$dbType = (isset($conf->db->type) && $conf->db->type === 'pgsql') ? 'pgsql' : 'mysql';

		return array(
			'DB_CONNECTION_TYPE' => 'host',
			'DB_DRIVER'          => $dbType,
			'DB_HOST'            => (isset($conf->db->host) ? $conf->db->host : '127.0.0.1'),
			'DB_PORT'            => (isset($conf->db->port) ? $conf->db->port : ''),
			'DB_NAME'            => (isset($conf->db->name) ? $conf->db->name : ''),
			'DB_USER'            => (isset($conf->db->user) ? $conf->db->user : ''),
			'DB_PASS'            => (isset($conf->db->pass) ? $conf->db->pass : ''),
			// Configurable in conf.php, so never assumed to be llx_.
			'DOLIBARR_DB_PREFIX' => (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_'),
			'SESSION_TYPE'     => 'custom',
			'AUTH_TYPE'        => 'custom',
			'IMAP_AUTH_NAME'   => getDolGlobalString('CYPHTWEBMAIL_IMAP_NAME', 'Webmail'),
			'IMAP_AUTH_SERVER' => getDolGlobalString('CYPHTWEBMAIL_IMAP_SERVER', 'localhost'),
			'IMAP_AUTH_PORT'   => getDolGlobalString('CYPHTWEBMAIL_IMAP_PORT', '993'),
			'IMAP_AUTH_TLS'    => getDolGlobalString('CYPHTWEBMAIL_IMAP_TLS', 'true'),
			// Not 'file': Hm_User_Config_File keys its encryption on the login
			// password, which under SSO is a fresh token every request.
			'USER_CONFIG_TYPE' => 'custom:Custom_User_Config',
			'USER_SETTINGS_DIR' => $dataDir . '/users',
			'ATTACHMENT_DIR'   => $dataDir . '/attachments',
			'ENABLE_REDIS'     => 'false',
			'ENABLE_MEMCACHED' => 'false',
			'ENABLE_DEBUG'     => 'false',
			'DEFAULT_LANGUAGE' => 'en',
			// "account" is where users add their IMAP mailbox after SSO.
			// "api_login" is what performSsoLogin() calls. "themes" serves
			// the Bootswatch CSS packs; without it the app renders unstyled.
			// dolibarr_contacts must appear here or config_gen.php never scans
			// its setup.php, and it must follow "contacts", whose load_contacts
			// handler it attaches to.
			'CYPHT_MODULES'    => 'core,contacts,dolibarr_contacts,imap,smtp,api_login,account,nux,developer,history,saved_searches,advanced_search,profiles,inline_message,imap_folders,keyboard_shortcuts,site,dynamic_login,sievefilters,themes',
			'DISABLE_FINGERPRINT' => 'true',
			'DISABLE_EMPTY_SUPERGLOBALS' => 'true',
			'SSO_SHARED_SECRET' => $this->sso->getOrCreateSsoSecret(),
			// Encrypts the mailbox passwords inside the stored config.
			'USER_CONFIG_SECRET' => $this->sso->getOrCreateConfigSecret(),
			'SESSION_DEBUG'      => getDolGlobalString('CYPHTWEBMAIL_SESSION_DEBUG', 'false'),
			'SESSION_TTL'        => getDolGlobalString('CYPHTWEBMAIL_SESSION_TTL', '604800'),
			'SESSION_GC_DIVISOR' => getDolGlobalString('CYPHTWEBMAIL_SESSION_GC_DIVISOR', '200'),
			'DISABLE_OPEN_BASE_DIR' => 'true',
			'DOLIBARR_CONTACTS_URL' => CyphtContactsBridge::resolveBridgeUrl(),
			'DOLIBARR_CONTACTS_TTL' => getDolGlobalString('CYPHTWEBMAIL_CONTACTS_TTL', '300'),
			'DOLIBARR_CONTACTS_TIMEOUT' => getDolGlobalString('CYPHTWEBMAIL_CONTACTS_TIMEOUT', '5'),
			'DOLIBARR_CONTACTS_INSECURE' => getDolGlobalString('CYPHTWEBMAIL_CONTACTS_INSECURE', 'false'),
			// Opened with target="_top" so it escapes the webmail iframe.
			'DOLIBARR_NEW_CONTACT_URL' => dol_buildpath('/contact/card.php', 2) . '?action=create',
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
