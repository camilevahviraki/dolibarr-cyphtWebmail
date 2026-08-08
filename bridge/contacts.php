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
$secret = getDolGlobalString('CYPHTWEBMAIL_SSO_SECRET', '');
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

$maxRows = getDolGlobalInt('CYPHTWEBMAIL_CONTACTS_MAX', 2000);
if ($limit > 0 && $limit < $maxRows) {
	$maxRows = $limit;
}

$searchSql = '';
if ($search !== '') {
	$like = "'%".$db->escape($db->escapeforlike($search))."%'";
	$searchSql = $like; // reused per query below with the right column set
}

$contacts = array();

/**
 * Shape a row the way Hm_Contact expects. Extra Dolibarr-specific data goes
*
 * @param string $email   Email address
 * @param string $name    Display name
 * @param string $group   Grouping label shown on the contacts page
 * @param array  $extra   Dolibarr-specific fields
 * @return array
 */
function cyphtBridgeContact($email, $name, $group, $extra)
{
	return array(
		'email_address' => $email,
		'display_name'  => ($name !== '' ? $name : $email),
		'group'         => $group,
		'source'        => 'dolibarr',
		'type'          => 'dolibarr',
		'external'      => true,
		'phone_number'  => isset($extra['phone']) ? $extra['phone'] : '',
		// Top level, not just inside all_fields: Cypht's
		'company'       => isset($extra['dol_company']) ? $extra['dol_company'] : '',
		'all_fields'    => $extra,
	);
}

// --- 3a. Contacts (llx_socpeople) -----------------------------------
$sql = "SELECT sp.rowid, sp.lastname, sp.firstname, sp.email, sp.phone as phone_pro, sp.phone_mobile,";
$sql .= " sp.poste, sp.fk_soc, s.nom as company";
$sql .= " FROM ".MAIN_DB_PREFIX."socpeople as sp";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = sp.fk_soc";
// 'contact', not 'socpeople': that is the element token core itself passes
$sql .= " WHERE sp.entity IN (".getEntity('contact').")";
$sql .= " AND sp.email IS NOT NULL AND sp.email <> ''";
$sql .= " AND sp.statut = 1";
if ($searchSql !== '') {
	$sql .= " AND (sp.email LIKE ".$searchSql." OR sp.lastname LIKE ".$searchSql;
	$sql .= " OR sp.firstname LIKE ".$searchSql." OR s.nom LIKE ".$searchSql.")";
}
$sql .= " ORDER BY sp.lastname, sp.firstname";
$sql .= $db->plimit($maxRows, 0);

$resql = $db->query($sql);
if (!$resql) {
	cyphtBridgeRespond(500, array('error' => 'Contact query failed: '.$db->lasterror()));
}
while ($obj = $db->fetch_object($resql)) {
	$name = trim($obj->firstname.' '.$obj->lastname);
	$contacts[] = cyphtBridgeContact(
		$obj->email,
		$name,
		($obj->company ? $obj->company : 'Dolibarr contacts'),
		array(
			'dol_type'    => 'contact',
			'dol_id'      => (int) $obj->rowid,
			'dol_socid'   => (int) $obj->fk_soc,
			'dol_company' => (string) $obj->company,
			'dol_job'     => (string) $obj->poste,
			'phone'       => ($obj->phone_pro ? $obj->phone_pro : (string) $obj->phone_mobile),
		)
	);
}
$db->free($resql);

// --- 3b. Third parties (llx_societe) --------------------------------
$sql = "SELECT s.rowid, s.nom, s.email, s.phone";
$sql .= " FROM ".MAIN_DB_PREFIX."societe as s";
$sql .= " WHERE s.entity IN (".getEntity('societe').")";
$sql .= " AND s.email IS NOT NULL AND s.email <> ''";
$sql .= " AND s.status = 1";
if ($searchSql !== '') {
	$sql .= " AND (s.email LIKE ".$searchSql." OR s.nom LIKE ".$searchSql.")";
}
$sql .= " ORDER BY s.nom";
$sql .= $db->plimit($maxRows, 0);

$resql = $db->query($sql);
if (!$resql) {
	cyphtBridgeRespond(500, array('error' => 'Third party query failed: '.$db->lasterror()));
}
while ($obj = $db->fetch_object($resql)) {
	$contacts[] = cyphtBridgeContact(
		$obj->email,
		(string) $obj->nom,
		'Dolibarr third parties',
		array(
			'dol_type'    => 'thirdparty',
			'dol_id'      => (int) $obj->rowid,
			'dol_socid'   => (int) $obj->rowid,
			'dol_company' => (string) $obj->nom,
			'phone'       => (string) $obj->phone,
		)
	);
}
$db->free($resql);

// De-duplicate on address: a contact record wins over the company generic
// address, because it was added first and carries a real person's name.
$seen = array();
$unique = array();
foreach ($contacts as $contact) {
	$key = strtolower($contact['email_address']);
	if (isset($seen[$key])) {
		continue;
	}
	$seen[$key] = true;
	$unique[] = $contact;
}

cyphtBridgeRespond(200, array(
	'contacts' => $unique,
	'count'    => count($unique),
	'entity'   => (int) $conf->entity,
	'truncated' => (count($contacts) >= ($maxRows * 2)),
));
