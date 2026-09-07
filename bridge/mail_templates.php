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
 * \file        bridge/mail_templates.php
 * \brief       Dolibarr email templates, for the compose screen in Cypht.
 *
 *              Visibility follows FormMail::getEMailTemplate() in
 *              htdocs/core/class/html.formmail.class.php: entity scoping plus
 *              "private = 0 OR fk_user = me". That clause is the access
 *              control, not a filter.
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
function cyphtMailTemplatesRespond($status, $body)
{
	global $db;

	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
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
	cyphtMailTemplatesRespond(403, array('error' => 'Module not enabled'));
}

$login = GETPOST('login', 'aZ09arobase');
$token = GETPOST('token', 'aZ09');


// ---------------------------------------------------------------------

require_once __DIR__.'/../class/integration/bridgeauth.class.php';

if ($token === '') {
	/* Session mode: main.inc.php has already authenticated. */
	global $user;
	if (empty($user->id)) {
		cyphtMailTemplatesRespond(403, array('error' => 'Not signed in'));
	}
	$bridgeUser = $user;
} else {
	$authStatus = 403;
	$authError = '';
	$bridgeUser = CyphtBridgeAuth::userFromToken($db, $login, $token, 'templates', $authStatus, $authError);
	if ($bridgeUser === null) {
		cyphtMailTemplatesRespond($authStatus, array('error' => $authError));
	}
}

$bridgeUser->loadRights();

// NOLOGIN leaves $conf->entity at its default; realign it with the user so
// getEntity() below filters correctly under Multicompany.
$conf->entity = ($bridgeUser->entity > 0 ? $bridgeUser->entity : 1);

// ---------------------------------------------------------------------

require_once __DIR__.'/../class/integration/mailtemplatecollector.class.php';

$collectError = '';
$result = CyphtMailTemplateCollector::collect($db, $bridgeUser, $langs, $collectError);
if ($result === null) {
	cyphtMailTemplatesRespond(500, array('error' => $collectError));
}

cyphtMailTemplatesRespond(200, $result);
