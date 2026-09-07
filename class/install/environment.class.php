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

require_once __DIR__ . '/../install/paths.class.php';
require_once __DIR__ . '/../auth/token.class.php';
require_once __DIR__ . '/../integration/contactsource.class.php';
require_once __DIR__ . '/../integration/mailtemplatesource.class.php';
require_once __DIR__ . '/../integration/contextsource.class.php';

/**
 * \file        class/install/environment.class.php
 * \ingroup     cyphtWebmail
 * \brief       Builds and writes Cypht's .env file from Dolibarr constants.
 */
class CyphtEnvironment
{
	/**
	 * @var string  Last error message, if any call returned false/failure.
	 */
	public $error = '';

	/**
	 * @var CyphtPaths
	 */
	private $paths;

	/**
	 * @var CyphtToken
	 */
	private $token;

	/**
	 * @param CyphtPaths $paths
	 * @param CyphtToken $token
	 */
	public function __construct(CyphtPaths $paths, CyphtToken $token)
	{
		$this->paths = $paths;
		$this->token = $token;
	}

	/**
	 * The half of the .env that owes nothing to Dolibarr.
	 *
	 * Static so an offline build can reach it, and shared with
	 * buildEnvOverrides() so the two build paths cannot compile different
	 * module lists. Contains no secrets, so it is safe to distribute.
	 *
	 * @return array<string,string>
	 */
	public static function buildTimeDefaults()
	{
		return array(
			'DB_CONNECTION_TYPE' => 'host',
			'SESSION_TYPE'     => 'custom',
			'AUTH_TYPE'        => 'custom',
			'USER_CONFIG_TYPE' => 'custom:Custom_User_Config',
			'ENABLE_REDIS'     => 'false',
			'ENABLE_MEMCACHED' => 'false',
			'ENABLE_DEBUG'     => 'false',
			'DEFAULT_LANGUAGE' => 'en',
			'CYPHT_MODULES'    => 'core,contacts,dolibarr_contacts,dolibarr_mail_templates,imap,dolibarr_context,smtp,api_login,account,nux,developer,history,saved_searches,advanced_search,profiles,inline_message,imap_folders,keyboard_shortcuts,site,dolibarr_prefs,dynamic_login,sievefilters,themes',
			'DISABLE_FINGERPRINT' => 'true',
			'DISABLE_EMPTY_SUPERGLOBALS' => 'true',
			'DISABLE_OPEN_BASE_DIR' => 'true',
		);
	}

	/**
	 * buildTimeDefaults() plus everything that is per installation: database
	 * credentials, generated secrets, data paths and bridge URLs.
	 *
	 * @return array<string,string>
	 */
	public function buildEnvOverrides()
	{
		global $conf;

		$dataDir = $this->paths->getDataDir();

		$dbType = (isset($conf->db->type) && $conf->db->type === 'pgsql') ? 'pgsql' : 'mysql';

		return array_merge(self::buildTimeDefaults(), array(
			'DB_DRIVER'          => $dbType,
			'DB_HOST'            => (isset($conf->db->host) ? $conf->db->host : '127.0.0.1'),
			'DB_PORT'            => (isset($conf->db->port) ? $conf->db->port : ''),
			'DB_NAME'            => (isset($conf->db->name) ? $conf->db->name : ''),
			'DB_USER'            => (isset($conf->db->user) ? $conf->db->user : ''),
			'DB_PASS'            => (isset($conf->db->pass) ? $conf->db->pass : ''),
			'DOLIBARR_DB_PREFIX' => (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_'),
			'USER_SETTINGS_DIR' => $dataDir . '/users',
			'ATTACHMENT_DIR'   => $dataDir . '/attachments',
			'SSO_SHARED_SECRET' => $this->token->getOrCreateSsoSecret(),
			'USER_CONFIG_SECRET' => $this->token->getOrCreateConfigSecret(),
			'SESSION_DEBUG'      => getDolGlobalString('CYPHTWEBMAIL_SESSION_DEBUG', 'false'),
			'SESSION_TTL'        => getDolGlobalString('CYPHTWEBMAIL_SESSION_TTL', '604800'),
			'SESSION_GC_DIVISOR' => getDolGlobalString('CYPHTWEBMAIL_SESSION_GC_DIVISOR', '200'),
			'DOLIBARR_CONTACTS_URL' => CyphtContactSource::resolveBridgeUrl(),
			'DOLIBARR_CONTACTS_TTL' => getDolGlobalString('CYPHTWEBMAIL_CONTACTS_TTL', '300'),
			'DOLIBARR_CONTACTS_TIMEOUT' => getDolGlobalString('CYPHTWEBMAIL_CONTACTS_TIMEOUT', '5'),
			'DOLIBARR_CONTACTS_INSECURE' => getDolGlobalString('CYPHTWEBMAIL_CONTACTS_INSECURE', 'false'),
			'DOLIBARR_MAIL_TEMPLATES_URL' => CyphtMailTemplateSource::resolveBridgeUrl(),
			// Longer than the contacts TTL: an address book changes as people
			// are added, a template list only when someone edits a template.
			'DOLIBARR_MAIL_TEMPLATES_TTL' => getDolGlobalString('CYPHTWEBMAIL_MAIL_TEMPLATES_TTL', '900'),
			'DOLIBARR_MAIL_TEMPLATES_TIMEOUT' => getDolGlobalString('CYPHTWEBMAIL_MAIL_TEMPLATES_TIMEOUT', '5'),
			'DOLIBARR_MAIL_TEMPLATES_INSECURE' => getDolGlobalString('CYPHTWEBMAIL_MAIL_TEMPLATES_INSECURE', 'false'),
			'DOLIBARR_CONTEXT_URL' => CyphtContextSource::resolveBridgeUrl(),
			// Shorter than the other two: this data changes while a mailbox is open.
			'DOLIBARR_CONTEXT_TTL' => getDolGlobalString('CYPHTWEBMAIL_CONTEXT_TTL', '120'),
			// Cards are cached per address; this caps how many.
			'DOLIBARR_CONTEXT_CACHE' => getDolGlobalString('CYPHTWEBMAIL_CONTEXT_CACHE', '20'),
			'DOLIBARR_CONTEXT_TIMEOUT' => getDolGlobalString('CYPHTWEBMAIL_CONTEXT_TIMEOUT', '5'),
			'DOLIBARR_CONTEXT_INSECURE' => getDolGlobalString('CYPHTWEBMAIL_CONTEXT_INSECURE', 'false'),
			// The write endpoint, keyed separately from the read one.
			'DOLIBARR_CONTEXT_CREATE_URL' => CyphtContextSource::resolveCreateUrl(),
			// Opened with target="_top" so it escapes the webmail iframe.
			'DOLIBARR_NEW_CONTACT_URL' => dol_buildpath('/contact/card.php', 2) . '?action=create',
		));
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
		$error = '';
		if (!self::writeEnvTo($this->paths->getCyphtPath(), $overrides, $error)) {
			$this->error = $error;
			return false;
		}

		if (!self::writeConfigTo($this->paths->getCyphtPath(), $error)) {
			$this->error = $error;
			return false;
		}

		return true;
	}

	/**
	 * The same write, without needing an instance.
	 *
	 * @param string $cyphtPath Cypht root inside vendor/
	 * @param array<string,string> $overrides Key/value pairs to force
	 * @param string $error Set on failure
	 * @param bool $fresh Start from .env.example even when a .env exists, so a
	 *                    build cannot inherit the values of the installation it
	 *                    happens to be running inside
	 * @return bool
	 */
	public static function writeEnvTo($cyphtPath, array $overrides, &$error = '', $fresh = false)
	{
		$envFile = $cyphtPath . '/.env';
		$source = (!$fresh && file_exists($envFile)) ? $envFile : $cyphtPath . '/.env.example';

		if (!file_exists($source)) {
			$error = 'Neither .env nor .env.example found in ' . $cyphtPath . '. Is Cypht actually installed under vendor/?';
			return false;
		}

		$lines = file($source, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			$error = 'Could not read ' . $source;
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
			$error = 'Could not write ' . $envFile . ' (permissions?)';
			return false;
		}

		return true;
	}

	/**
	 * Pin the settings that decide auth, the session and the module set.
	 *
	 * Cypht builds its configuration by merging every config/*.php file in
	 * glob order, then reads most values through env(), which is getenv()
	 * only. getenv() depends on the .env having been parsed into the process
	 * environment, which varies by SAPI and php.ini, so a correct .env on
	 * disk does not guarantee the value arrives. Where it does not, app.php
	 * silently substitutes upstream Cypht's defaults, whose module list has
	 * no 'site' and whose auth_type is DB, and the module's own Custom_Auth
	 * is then never loaded.
	 *
	 * Writing them as a config file instead takes them off getenv entirely.
	 * The name sorts after app.php, which is what makes it win the merge.
	 *
	 * @param string $cyphtPath Cypht root inside vendor/
	 * @param string $error Set on failure
	 * @return bool True on success
	 */
	public static function writeConfigTo($cyphtPath, &$error = '')
	{
		$defaults = self::buildTimeDefaults();

		$config = array(
			'modules' => explode(',', $defaults['CYPHT_MODULES']),
			'auth_type' => $defaults['AUTH_TYPE'],
			'auth_class' => 'Custom_Auth',
			'session_type' => $defaults['SESSION_TYPE'],
			'session_class' => 'Custom_Session',
			'user_config_type' => $defaults['USER_CONFIG_TYPE'],
		);

		$dir = $cyphtPath . '/config';
		if (!is_dir($dir)) {
			$error = 'No config directory in ' . $cyphtPath;
			return false;
		}

		/* Sorts after app.php, which is what makes it win. */
		$file = $dir . '/zz_dolibarr.php';
		$body = "<?php\n\n/* Written by the cyphtwebmail build. Do not edit. */\n\nreturn "
			. var_export($config, true) . ";\n";

		if (file_put_contents($file, $body) === false) {
			$error = 'Could not write ' . $file . ' (permissions?)';
			return false;
		}

		return true;
	}
}
