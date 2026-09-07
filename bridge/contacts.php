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

// This is a machine-to-machine JSON endpoint: no session, no menus, no
if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
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

if ($login === '' || $token === '') {
	cyphtBridgeRespond(400, array('error' => 'Missing login or token'));
}

// ---------------------------------------------------------------------

// Read the constant directly rather than going through
require_once __DIR__.'/../class/install/config.class.php';
// Not llx_const: dolibarr_set_const() encrypts anything whose name ends
// in _SECRET, and the webmail reads its copy over raw PDO before
// Dolibarr is loaded, so the two ends would sign with different values.
$secret = CyphtConfig::get($db, 'SSO_SHARED_SECRET', '');
if ($secret === '') {
	cyphtBridgeRespond(503, array('error' => 'SSO secret not initialised, run the module build first'));
}

if (strpos($token, '.') === false) {
	cyphtBridgeRespond(403, array('error' => 'Malformed token'));
}

list($timestamp, $signature) = explode('.', $token, 2);
if (!ctype_digit($timestamp)) {
	cyphtBridgeRespond(403, array('error' => 'Malformed token'));
}

// Same 60s anti-replay window as Custom_Auth::check_credentials().
if (abs(time() - (int) $timestamp) > 60) {
	cyphtBridgeRespond(403, array('error' => 'Token expired'));
}

$expected = hash_hmac('sha256', $login.'|'.$timestamp.'|contacts', $secret);
if (!hash_equals($expected, $signature)) {
	cyphtBridgeRespond(403, array('error' => 'Bad signature'));
}

// ---------------------------------------------------------------------

$bridgeUser = new User($db);
if ($bridgeUser->fetch(0, $login) <= 0) {
	cyphtBridgeRespond(403, array('error' => 'Unknown user'));
}
if (isset($bridgeUser->statut) && $bridgeUser->statut == 0) {
	cyphtBridgeRespond(403, array('error' => 'Disabled user'));
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
