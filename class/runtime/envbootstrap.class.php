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
 * \file        class/runtime/envbootstrap.class.php
 * \ingroup     cyphtwebmail
 * \brief       Supplies Cypht's per-installation configuration at runtime,
 *              from conf.php and the database, without writing to disk.
 *
 *              Plain PHP by necessity: runs before Dolibarr is loaded and
 *              before Cypht's autoloader, so it can use neither.
 */
class CyphtEnvBootstrap
{
	/**
	 * @var string Last failure, for the caller to surface or log.
	 */
	public $error = '';

	/**
	 * @var string Absolute path to the module root.
	 */
	private $moduleRoot;

	/**
	 * @param string $moduleRoot Directory holding public/, class/, vendor/
	 */
	public function __construct($moduleRoot)
	{
		$this->moduleRoot = rtrim($moduleRoot, '/\\');
	}

	/**
	 * Populating the environment before Cypht loads its config replaces the
	 * .env write. Existing values win, so a real .env entry stays an override.
	 *
	 * @return bool True if the per-installation values are now available
	 */
	public function apply()
	{
		$conf = $this->readDolibarrConf();
		if ($conf === null) {
			return false;
		}

		$values = $this->fromConf($conf);

		/* Cypht's own VERSION constant is its internal framework number (0.1),
		 * not the release. The real one is recorded by the build, so publish it
		 * here for anything that needs to stamp what wrote a record. */
		$build = $this->readBuildInfo();
		if (isset($build['cypht_version']) && $build['cypht_version'] !== '') {
			$values['CYPHT_VERSION'] = (string) $build['cypht_version'];
		}

		$consts = $this->readConsts($conf);
		if ($consts === null) {
			/* Reaching conf.php but not the database is worth distinguishing:
			 * the first is a layout problem, the second a credentials or
			 * server problem. Both leave $values usable for the paths. */
			$this->error = 'Read Dolibarr conf.php but could not query its database: ' . $this->error;
		} else {
			$values = array_merge($values, $consts);
		}

		foreach ($values as $key => $value) {
			if ($value === null || $value === '') {
				continue;
			}

			if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
				$value = $_ENV[$key];
			} else {
				$_ENV[$key] = (string) $value;
			}

			/* Both stores: Hm_Environment::get() reads $_ENV, but config/app.php
			 * resolves 125 settings through env(), which is getenv() only.
			 * Symfony's loader skips any name already in $_ENV, so it never
			 * putenv()s these and they would fall back to upstream defaults. */
			if (getenv($key) === false) {
				putenv($key . '=' . $value);
			}
		}

		return $consts !== null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function readBuildInfo()
	{
		$file = $this->moduleRoot . '/build.json';
		if (!is_readable($file)) {
			return array();
		}

		$data = json_decode((string) file_get_contents($file), true);

		return is_array($data) ? $data : array();
	}

	/**
	 * Locate and evaluate Dolibarr's conf.php.
	 *
	 * Included inside a method so its $dolibarr_* variables stay out of the
	 * global scope Cypht is about to use.
	 *
	 * @return array<string,mixed>|null
	 */
	private function readDolibarrConf()
	{
		$path = $this->locateDolibarrConf();
		if ($path === null) {
			$this->error = 'Could not find Dolibarr conf.php from ' . $this->moduleRoot
				. '. Set CYPHTWEBMAIL_DOLIBARR_CONF in the environment to point at it.';
			return null;
		}

		/* conf.php only assigns variables, but include it defensively: a
		 * parse error here must not take down the whole request with a fatal
		 * that says nothing useful. */
		$dolibarr_main_db_type = '';
		$dolibarr_main_db_host = '';
		$dolibarr_main_db_port = '';
		$dolibarr_main_db_name = '';
		$dolibarr_main_db_user = '';
		$dolibarr_main_db_pass = '';
		$dolibarr_main_db_prefix = '';
		$dolibarr_main_data_root = '';
		$dolibarr_main_url_root = '';

		include $path;

		if ($dolibarr_main_db_name === '') {
			$this->error = 'Dolibarr conf.php at ' . $path . ' defines no database name.';
			return null;
		}

		return array(
			'type' => $dolibarr_main_db_type !== '' ? $dolibarr_main_db_type : 'mysqli',
			'host' => $dolibarr_main_db_host,
			'port' => $dolibarr_main_db_port,
			'name' => $dolibarr_main_db_name,
			'user' => $dolibarr_main_db_user,
			'pass' => $dolibarr_main_db_pass,
			'prefix' => $dolibarr_main_db_prefix !== '' ? $dolibarr_main_db_prefix : 'llx_',
			'data_root' => $dolibarr_main_data_root,
			'url_root' => rtrim($dolibarr_main_url_root, '/'),
			// conf.php sits at <web root>/conf/conf.php, so this is the
			// directory url_root actually points at.
			'root_dir' => dirname(dirname($path)),
		);
	}

	/**
	 * Walk up from the module looking for Dolibarr's conf.php.
	 *
	 * Every ancestor, not a fixed depth: htdocs/custom/<module> is only the
	 * convention. CYPHTWEBMAIL_DOLIBARR_CONF wins, for a Dolibarr that is not
	 * an ancestor at all.
	 *
	 * @return string|null
	 */
	private function locateDolibarrConf()
	{
		$override = getenv('CYPHTWEBMAIL_DOLIBARR_CONF');
		if (is_string($override) && $override !== '' && is_readable($override)) {
			return $override;
		}

		$dir = $this->moduleRoot;

		while (true) {
			foreach (array($dir . '/conf/conf.php', $dir . '/htdocs/conf/conf.php') as $candidate) {
				if (is_readable($candidate)) {
					return $candidate;
				}
			}

			// dirname() is its own parent at the filesystem root, on both platforms.
			$parent = dirname($dir);
			if ($parent === $dir) {
				return null;
			}
			$dir = $parent;
		}
	}

	/**
	 * The values conf.php alone can answer.
	 *
	 * Paths are derived rather than stored: a stored absolute path goes stale
	 * when the installation moves, and stale fails later than absent.
	 *
	 * @param array<string,mixed> $conf
	 * @return array<string,string>
	 */
	private function fromConf(array $conf)
	{
		$dataDir = rtrim($conf['data_root'], '/\\') . '/cyphtwebmail';
		$moduleUrl = $conf['url_root'] . $this->moduleUrlPath($conf['root_dir']);

		return array(
			'DB_DRIVER' => ($conf['type'] === 'pgsql') ? 'pgsql' : 'mysql',
			'DB_HOST' => $conf['host'],
			'DB_PORT' => $conf['port'],
			'DB_NAME' => $conf['name'],
			'DB_USER' => $conf['user'],
			'DB_PASS' => $conf['pass'],
			'DOLIBARR_DB_PREFIX' => $conf['prefix'],
			'USER_SETTINGS_DIR' => $dataDir . '/users',
			'DOLIBARR_CACHE_DIR' => $dataDir . '/cache',
			/* Lets the Cypht half require code shared with this one, which
			 * it cannot otherwise locate: its own __DIR__ is inside vendor. */
			'DOLIBARR_MODULE_ROOT' => $this->moduleRoot,
			'ATTACHMENT_DIR' => $dataDir . '/attachments',
			'DOLIBARR_CONTACTS_URL' => $moduleUrl . '/bridge/contacts.php',
			'DOLIBARR_MAIL_TEMPLATES_URL' => $moduleUrl . '/bridge/mail_templates.php',
			'DOLIBARR_CONTEXT_URL' => $moduleUrl . '/bridge/context.php',
			'DOLIBARR_CONTEXT_CREATE_URL' => $moduleUrl . '/bridge/create.php',
			'DOLIBARR_NEW_CONTACT_URL' => $conf['url_root'] . '/contact/card.php?action=create',
		);
	}

	/**
	 * Where the module sits under the Dolibarr web root, as a URL path.
	 *
	 * Measured, not assumed to be custom/<module>: a wrong path here is a 404
	 * on the bridges that reads like a signature fault. Case-insensitive on
	 * Windows. Falls back to the conventional location for a module symlinked
	 * in, which has no path under the web root to measure.
	 *
	 * @param string $rootDir Directory url_root points at
	 * @return string Leading slash, no trailing slash
	 */
	private function moduleUrlPath($rootDir)
	{
		$root = rtrim(str_replace('\\', '/', $rootDir), '/');
		$module = rtrim(str_replace('\\', '/', $this->moduleRoot), '/');

		$inside = (DIRECTORY_SEPARATOR === '\\')
			? stripos($module, $root . '/') === 0
			: strpos($module, $root . '/') === 0;

		if ($root !== '' && $inside) {
			return substr($module, strlen($root));
		}

		return '/custom/' . basename($module);
	}

	/**
	 * The stored half: generated secrets and whatever the setup page saved.
	 *
	 * @param array<string,mixed> $conf
	 * @return array<string,string>|null Null if the database was unreachable
	 */
	private function readConsts(array $conf)
	{
		/* An allow list rather than a CYPHTWEBMAIL_% sweep: llx_const is
		 * shared, so a suggestively named constant must not reach Cypht. */
		$map = array(
			'CYPHTWEBMAIL_SESSION_DEBUG' => 'SESSION_DEBUG',
			'CYPHTWEBMAIL_SESSION_TTL' => 'SESSION_TTL',
			'CYPHTWEBMAIL_SESSION_GC_DIVISOR' => 'SESSION_GC_DIVISOR',
			'CYPHTWEBMAIL_CONTACTS_TTL' => 'DOLIBARR_CONTACTS_TTL',
			'CYPHTWEBMAIL_CONTACTS_TIMEOUT' => 'DOLIBARR_CONTACTS_TIMEOUT',
			'CYPHTWEBMAIL_CONTACTS_INSECURE' => 'DOLIBARR_CONTACTS_INSECURE',
			'CYPHTWEBMAIL_MAIL_TEMPLATES_TTL' => 'DOLIBARR_MAIL_TEMPLATES_TTL',
			'CYPHTWEBMAIL_MAIL_TEMPLATES_TIMEOUT' => 'DOLIBARR_MAIL_TEMPLATES_TIMEOUT',
			'CYPHTWEBMAIL_MAIL_TEMPLATES_INSECURE' => 'DOLIBARR_MAIL_TEMPLATES_INSECURE',
			'CYPHTWEBMAIL_CONTEXT_TTL' => 'DOLIBARR_CONTEXT_TTL',
			'CYPHTWEBMAIL_CONTEXT_CACHE' => 'DOLIBARR_CONTEXT_CACHE',
			'CYPHTWEBMAIL_CONTEXT_TIMEOUT' => 'DOLIBARR_CONTEXT_TIMEOUT',
			'CYPHTWEBMAIL_CONTEXT_INSECURE' => 'DOLIBARR_CONTEXT_INSECURE',
			'CYPHTWEBMAIL_BRIDGE_HTTP_AUTH' => 'DOLIBARR_BRIDGE_HTTP_AUTH',
			'CYPHTWEBMAIL_BRIDGE_URL' => 'DOLIBARR_CONTACTS_URL',
			'CYPHTWEBMAIL_BRIDGE_MAIL_TEMPLATES_URL' => 'DOLIBARR_MAIL_TEMPLATES_URL',
			'CYPHTWEBMAIL_BRIDGE_CONTEXT_URL' => 'DOLIBARR_CONTEXT_URL',
			'CYPHTWEBMAIL_BRIDGE_CONTEXT_CREATE_URL' => 'DOLIBARR_CONTEXT_CREATE_URL',
		);

		$pdo = $this->connect($conf);
		if ($pdo === null) {
			return null;
		}

		$table = $conf['prefix'] . 'const';
		$names = array_keys($map);
		$slots = implode(',', array_fill(0, count($names), '?'));

		try {
			/* Ordered by entity so a value scoped to the current entity
			 * overwrites the global one during the loop below. */
			$stmt = $pdo->prepare(
				'SELECT name, value FROM ' . $table . ' WHERE name IN (' . $slots . ') ORDER BY entity ASC'
			);
			$stmt->execute($names);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (Exception $e) {
			$this->error = 'Query on ' . $table . ' failed: ' . $e->getMessage();
			return null;
		}

		$out = $this->readModuleConfig($pdo, $conf);

		foreach ($rows as $row) {
			$name = isset($row['name']) ? $row['name'] : '';
			if (!isset($map[$name])) {
				continue;
			}
			$out[$map[$name]] = isset($row['value']) ? (string) $row['value'] : '';
		}

		return $out;
	}

	/**
	 * The module's own configuration table.
	 *
	 * Not llx_const: dolibarr_set_const() encrypts anything ending in _SECRET
	 * or _KEY, and this reader is raw PDO, so it would get ciphertext and
	 * every signed bridge request would fail on signature.
	 *
	 * @param PDO $pdo
	 * @param array<string,mixed> $conf
	 * @return array<string,string>
	 */
	private function readModuleConfig(PDO $pdo, array $conf)
	{
		$table = $conf['prefix'] . 'cyphtwebmail_config';

		try {
			$stmt = $pdo->query('SELECT name, value FROM ' . $table . ' ORDER BY entity ASC');
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (Exception $e) {
			/* Not fatal on its own: an installation that has not been
			 * activated since this table was introduced simply has nothing
			 * here yet, and the caller still gets the paths. */
			$this->error = 'Could not read ' . $table . ': ' . $e->getMessage();
			return array();
		}

		$out = array();
		foreach ($rows as $row) {
			if (!isset($row['name'])) {
				continue;
			}
			$out[(string) $row['name']] = isset($row['value']) ? (string) $row['value'] : '';
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $conf
	 * @return PDO|null
	 */
	private function connect(array $conf)
	{
		$driver = ($conf['type'] === 'pgsql') ? 'pgsql' : 'mysql';
		$dsn = $driver . ':host=' . $conf['host'];
		if ($conf['port'] !== '' && $conf['port'] !== null) {
			$dsn .= ';port=' . $conf['port'];
		}
		$dsn .= ';dbname=' . $conf['name'];
		if ($driver === 'mysql') {
			$dsn .= ';charset=utf8mb4';
		}

		try {
			return new PDO($dsn, $conf['user'], $conf['pass'], array(
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_TIMEOUT => 5,
			));
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return null;
		}
	}
}
