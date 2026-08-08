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
 * \file        class/auth/sso.class.php
 * \ingroup     cyphtWebmail
 * \brief       Dolibarr -> Cypht single sign-on bridge. Owns the shared
 *              HMAC secret, short-lived login tokens, and the in-process
 *              "functional login" call. The modules/site override itself is
 *              installed by CyphtModuleInstaller.
 */
class CyphtSso
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
	 * @param DoliDB $db Database handler
	 * @param CyphtPaths $paths
	 */
	public function __construct($db, CyphtPaths $paths)
	{
		$this->db = $db;
		$this->paths = $paths;
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

		$secret = bin2hex(random_bytes(32));
		dolibarr_set_const($this->db, 'CYPHTWEBMAIL_SSO_SECRET', $secret, 'chaine', 0, '', $conf->entity);

		return $secret;
	}

	/**
	 * Filesystem path of a user's Cypht settings file.
	 *
	 * MUST stay in step with Custom_User_Config::get_path() in the generated
	 * modules/site/lib.php: Cypht writes the file, Dolibarr deletes it, and
	 * neither can see the other's code. The readable prefix is cosmetic; the
	 * sha256 fragment is what keeps two logins that sanitise identically
	 * ("jean dupont" and "jean_dupont") from sharing one file.
	 *
	 * @param string $login Dolibarr login
	 * @return string
	 */
	public function getUserSettingsPath($login)
	{
		$dir = $this->paths->getDataDir() . '/users';
		$safe = substr(preg_replace('/[^a-zA-Z0-9_.@-]/', '_', (string) $login), 0, 64);
		$fingerprint = substr(hash('sha256', (string) $login), 0, 12);

		return $dir . '/' . $safe . '-' . $fingerprint . '.json';
	}

	/**
	 * Pre-collision-fix filename, still cleaned up on user deletion so an
	 * upgrade does not strand an old file holding mailbox credentials.
	 *
	 * @param string $login Dolibarr login
	 * @return string
	 */
	public function getLegacyUserSettingsPath($login)
	{
		$dir = $this->paths->getDataDir() . '/users';

		return $dir . '/' . preg_replace('/[^a-zA-Z0-9_.@-]/', '_', (string) $login) . '.json';
	}

	/**
	 * Key encrypting the mailbox passwords in the stored user config.
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


	/**
	 * Logs the given Dolibarr user into Cypht via its "functional login"
	 * SSO option (calls cypht_login() in-process, setting the
	 * hm_id/hm_session cookies). Must be called before any HTML output.
	 *
	 * Skips the login entirely if a live session already exists for this
	 * user (see hasLiveSsoSession()): index.php calls this on
	 * every page load, and cypht_login() always resets the session data,
	 * which was silently discarding settings/servers that Cypht hadn't
	 * yet flagged for permanent storage. Requests made inside the iframe
	 * reuse the existing session cookie and never hit this path.
	 *
	 * @param string $login Dolibarr username to log into Cypht as
	 * @param string $cyphtUrl URL of the published Cypht app, need not be
	 *                          absolute already, see absolutizeUrl()
	 * @return bool true if Cypht accepted the SSO token, or a live session
	 *              already existed and was left alone
	 */
	public function performSsoLogin($login, $cyphtUrl)
	{
		if ($this->hasLiveSsoSession($login)) {
			return true;
		}

		$apiFile = $this->paths->getCyphtPath() . '/modules/api_login/api.php';
		if (!is_readable($apiFile)) {
			$this->error = 'modules/api_login/api.php not found; was the "api_login" module built into CYPHT_MODULES?';
			return false;
		}

		require_once $apiFile;

		$token = $this->generateSsoLoginToken($login);

		$ok = cypht_login($login, $token, $this->absolutizeUrl($cyphtUrl));
		if ($ok) {
			$this->rememberSsoSession($login);
		}

		return $ok;
	}

	/**
	 * Cookie recording which Dolibarr login the current Cypht session
	 * belongs to, separate from Cypht's own hm_session/hm_id.
	 *
	 * @return string
	 */
	private function ssoUserCookieName()
	{
		return 'cyphtwebmail_ssouser';
	}

	/**
	 * True if this browser already has a valid, still-on-disk Cypht
	 * session for $login: our own login-tracking cookie matches, Cypht's
	 * own hm_session cookie is set, and the session file it names still
	 * exists on disk.
	 *
	 * @param string $login
	 * @return bool
	 */
	private function hasLiveSsoSession($login)
	{
		if (empty($_COOKIE['hm_session']) || empty($_COOKIE['hm_id']) || empty($_COOKIE[$this->ssoUserCookieName()])) {
			return false;
		}

		if (!hash_equals((string) $login, (string) $_COOKIE[$this->ssoUserCookieName()])) {
			return false;
		}

		$sessionKey = preg_replace('/[^a-f0-9]/', '', (string) $_COOKIE['hm_session']);
		if ($sessionKey === '') {
			return false;
		}

		// Mirrors Custom_Session::session_file()'s naming convention.
		$sessionFile = $this->paths->getDataDir() . '/sso_sessions/' . $sessionKey . '.session';

		return is_readable($sessionFile);
	}

	/**
	 * Records which Dolibarr login the session cypht_login() just
	 * established, for the next request's hasLiveSsoSession() check.
	 * Session-lifetime cookie only; it authenticates nothing itself.
	 *
	 * @param string $login
	 * @return void
	 */
	private function rememberSsoSession($login)
	{
		$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
		setcookie($this->ssoUserCookieName(), $login, array(
			'path' => '/',
			'secure' => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		));
	}

	/**
	 * cypht_login() needs a real absolute URL to work out the cookie
	 * domain; dol_buildpath() can return a host-relative one. Prepends
	 * the current request's scheme+host if $url doesn't have one already.
	 *
	 * @param string $url
	 * @return string
	 */
	private function absolutizeUrl($url)
	{
		if (preg_match('#^https?://#i', $url)) {
			return $url;
		}

		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		$path = (substr($url, 0, 1) === '/') ? $url : '/' . $url;

		return $scheme . '://' . $host . $path;
	}
}
