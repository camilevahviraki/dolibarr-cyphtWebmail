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
 * \file        bridge/contacts.php
 */

/* Two callers, two ways in. The browser reaches this from the webmail
 * iframe, same origin as Dolibarr, so it carries the user's session cookie
 * and main.inc.php authenticates it. A server-to-server caller has no
 * cookie and signs an HMAC instead, and NOLOGIN keeps main.inc.php from
 * answering it with a login form. The choice has to be made here, before
 * main.inc.php loads. */
if (!empty($_GET['token']) || !empty($_POST['token'])) {
	if (!defined('NOLOGIN')) {
		define('NOLOGIN', '1');
	}
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

// Load Dolibarr environment (module lives at htdocs/custom/cyphtWebmail/bridge).
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	header('Content-Type: application/json');
	print json_encode(array('error' => 'Include of main fails'));
	exit;
}

/**
 * Emit a JSON response and stop.
*
 * @param int   $status HTTP status code
 * @param array $body   Payload to encode
 * @return void
 */
function cyphtBridgeRespond($status, $body)
{
	global $db;

	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	// Nothing here is cacheable: it is per-user data behind a 60s token.
	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('X-Content-Type-Options: nosniff');
	print json_encode($body);

	if (is_object($db)) {
		$db->close();
	}
	exit;
}

global $conf, $db;

if (!isModEnabled('cyphtwebmail')) {
	cyphtBridgeRespond(403, array('error' => 'Module not enabled'));
}

// 'aZ09arobase' (a-z0-9_-.@) covers every character a Dolibarr login may
$login = GETPOST('login', 'aZ09arobase');
$token = GETPOST('token', 'aZ09');
$search = GETPOST('search', 'alphanohtml');
$limit = GETPOSTINT('limit');


// ---------------------------------------------------------------------

// Read the constant directly rather than going through
require_once __DIR__.'/../class/integration/bridgeauth.class.php';

if ($token === '') {
	/* Session mode: main.inc.php has already authenticated. */
	global $user;
	if (empty($user->id)) {
		cyphtBridgeRespond(403, array('error' => 'Not signed in'));
	}
	$bridgeUser = $user;
} else {
	$authStatus = 403;
	$authError = '';
	$bridgeUser = CyphtBridgeAuth::userFromToken($db, $login, $token, 'contacts', $authStatus, $authError);
	if ($bridgeUser === null) {
		cyphtBridgeRespond($authStatus, array('error' => $authError));
	}
}

$bridgeUser->loadRights();

// NOLOGIN leaves $conf->entity at its default; realign it with the user so
// getEntity() below filters correctly under Multicompany.
$conf->entity = ($bridgeUser->entity > 0 ? $bridgeUser->entity : 1);

if (!$bridgeUser->hasRight('societe', 'lire')) {
	cyphtBridgeRespond(403, array('error' => 'User cannot read third parties'));
}

// ---------------------------------------------------------------------

require_once __DIR__.'/../class/integration/contactcollector.class.php';

$collectError = '';
$result = CyphtContactCollector::collect($db, $bridgeUser, $limit, $search, $collectError);
if ($result === null) {
	cyphtBridgeRespond(500, array('error' => $collectError));
}

cyphtBridgeRespond(200, $result);
