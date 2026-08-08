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

/**
 * \file        class/contacts/cyphtcontactsbridge.class.php
 * \ingroup     cyphtWebmail
 * \brief       Installs the "dolibarr_contacts" Cypht module set, which
 *              exposes Dolibarr third parties and contacts to Cypht as a
 *              read-only contact source.
 *
 *              The module set itself lives in templates/dolibarr_contacts
 *              and is copied into vendor/jason-munro/cypht on every build,
 *              so a "composer update" cannot silently revert it.
 */
class CyphtContactsBridge
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
	 * Module set name. Must also appear in CyphtEnvConfig's CYPHT_MODULES
	 * list, or config_gen.php never scans it.
	 */
	const MODULE_NAME = 'dolibarr_contacts';

	/**
	 * @param DoliDB $db Database handler
	 * @param CyphtInstallState $paths
	 */
	public function __construct($db, CyphtInstallState $paths)
	{
		$this->db = $db;
		$this->paths = $paths;
	}

	/**
	 * Absolute URL of the contact feed the module set calls.
	 *
	 * @return string
	 */
	public function getBridgeUrl()
	{
		return self::resolveBridgeUrl();
	}

	/**
	 * Static form, so CyphtEnvConfig can build the .env line without an
	 * instance and without a $db handle it has no other use for.
	 *
	 * @return string
	 */
	public static function resolveBridgeUrl()
	{
		// Escape hatch for setups where Dolibarr's public URL is not
		// reachable from the webserver back to itself.
		$url = getDolGlobalString('CYPHTWEBMAIL_BRIDGE_URL', '');
		if ($url !== '') {
			return $url;
		}

		return dol_buildpath('/cyphtWebmail/bridge/contacts.php', 2);
	}

}
