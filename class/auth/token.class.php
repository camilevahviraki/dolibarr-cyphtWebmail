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

/**
 * \file        class/auth/token.class.php
 * \ingroup     cyphtWebmail
 * \brief       Shared secrets and the short-lived HMAC assertions that prove
 *              a request belongs to a given Dolibarr user.
 */
class CyphtToken
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
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Secret signing the short-lived SSO tokens (see generateSsoLoginToken()).
	 * Persisted in llx_const and mirrored into Cypht's .env so
	 * Custom_Auth::check_credentials() can verify against it.
	 *
	 * @return string
	 */
	public function getOrCreateSsoSecret()
	{
		global $conf;

		$secret = getDolGlobalString('CYPHTWEBMAIL_SSO_SECRET', '');
		if ($secret !== '') {
			return $secret;
		}

		// Not loaded by master.inc.php, so a first command line build would
		// otherwise die here rather than minting the secret.
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

		$secret = bin2hex(random_bytes(32));
		dolibarr_set_const($this->db, 'CYPHTWEBMAIL_SSO_SECRET', $secret, 'chaine', 0, '', $conf->entity);

		return $secret;
	}

	/**
	 * Filesystem path of a user's Cypht settings file.

	 *
	 * Separate from the SSO secret: that one authenticates short-lived login
	 * assertions, this one protects data at rest. Server-held rather than
	 * derived from the user, since under SSO there is no stable password.
	 *
	 * @return string
	 */
	public function getOrCreateConfigSecret()
	{
		global $conf;

		$secret = getDolGlobalString('CYPHTWEBMAIL_CONFIG_SECRET', '');
		if ($secret !== '') {
			return $secret;
		}

		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

		$secret = bin2hex(random_bytes(32));
		dolibarr_set_const($this->db, 'CYPHTWEBMAIL_CONFIG_SECRET', $secret, 'chaine', 0, '', $conf->entity);

		return $secret;
	}

	/**
	 * HMAC token proving "this is really the current Dolibarr user", passed
	 * to Cypht's cypht_login() as the password. Never a real mailbox
	 * credential. Valid for 60s to limit replay.
	 *
	 * @param string $login Dolibarr username to embed in the token
	 * @return string
	 */
	public function generateSsoLoginToken($login)
	{
		$secret = $this->getOrCreateSsoSecret();
		$timestamp = time();
		$signature = hash_hmac('sha256', $login . '|' . $timestamp, $secret);

		return $timestamp . '.' . $signature;
	}
}
