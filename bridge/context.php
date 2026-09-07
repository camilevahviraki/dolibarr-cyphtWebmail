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
 * \file        bridge/context.php
 * \brief       Who one email address is in Dolibarr, and what is open against
 *              them, for the message view in Cypht.
 */

/* Two callers, two ways in.
 *
 * The browser reaches this from the webmail iframe, which is the same origin
 * as Dolibarr, so it arrives with the user's session cookie and whatever else
 * the site puts in front of Dolibarr. Nothing is signed and NOLOGIN must stay
 * off, so main.inc.php authenticates and $user is the real one.
 *
 * A server-to-server caller has no cookie, so it signs an HMAC instead and
 * NOLOGIN keeps main.inc.php from redirecting it to a login form.
 *
 * Deciding on the token has to happen here, before main.inc.php is loaded. */
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
 * @param int   $status HTTP status code
 * @param array $body   Payload to encode
 * @return void
 */
function cyphtContextRespond($status, $body)
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

global $conf, $db, $langs;

if (!isModEnabled('cyphtwebmail')) {
	cyphtContextRespond(403, array('error' => 'Module not enabled'));
}

$login = GETPOST('login', 'aZ09arobase');
$token = GETPOST('token', 'aZ09');
$email = trim(GETPOST('email', 'nohtml'));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
	cyphtContextRespond(400, array('error' => 'Missing or malformed email'));
}

// --- 1. Who is asking -------------------------------------------------

require_once __DIR__.'/../class/integration/bridgeauth.class.php';

if ($token === '') {
	/* Session mode: main.inc.php has already authenticated. */
	global $user;
	if (empty($user->id)) {
		cyphtContextRespond(403, array('error' => 'Not signed in'));
	}
	$bridgeUser = $user;
} else {
	$authStatus = 403;
	$authError = '';
	$bridgeUser = CyphtBridgeAuth::userFromToken($db, $login, $token, 'context', $authStatus, $authError);
	if ($bridgeUser === null) {
		cyphtContextRespond($authStatus, array('error' => $authError));
	}
}

$bridgeUser->loadRights();

// NOLOGIN leaves $conf->entity at its default; realign it with the user.
$conf->entity = ($bridgeUser->entity > 0 ? $bridgeUser->entity : 1);

// Module permission, then Dolibarr's own right. Both must pass.
if (!$bridgeUser->hasRight('cyphtwebmail', 'context', 'read')) {
	cyphtContextRespond(403, array('error' => 'User is not allowed sender records in the webmail'));
}

if (!$bridgeUser->hasRight('societe', 'lire')) {
	cyphtContextRespond(403, array('error' => 'User cannot read third parties'));
}

$canCreate = $bridgeUser->hasRight('cyphtwebmail', 'context', 'create')
	&& $bridgeUser->hasRight('societe', 'creer');

// --- 3. Helpers -------------------------------------------------------

/**
 * Absolute URL of a Dolibarr page. Absolute, not root relative, because the
 * @param string $path  Path below htdocs, leading slash included
 * @param string $query Query string without the leading '?'
 * @return string
 */
function cyphtContextUrl($path, $query = '')
{
	$url = dol_buildpath($path, 2);
	if ($query !== '') {
		$url .= (strpos($url, '?') === false ? '?' : '&').$query;
	}
	return $url;
}

/**
 * Money, formatted the way Dolibarr formats it everywhere else.
 * @param float $amount Amount
 * @return string
 */
function cyphtContextPrice($amount)
{
	global $conf, $langs;

	$formatted = price((float) $amount, 0, $langs, 1, -1, -1, $conf->currency);

	return html_entity_decode($formatted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * A date, or an empty string when the column was null.
 * @param string $sqlDate Raw column value
 * @return string
 */
function cyphtContextDate($sqlDate)
{
	global $db, $langs;

	if (empty($sqlDate)) {
		return '';
	}

	// 'tzserver': NOLOGIN has no session to read a user timezone from.
	return html_entity_decode(dol_print_date($db->jdate($sqlDate), 'day', 'tzserver', $langs), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Run one block: a headline count and total, plus the few most recent rows.
 * @param array $spec Block definition, see the calls below
 * @return array|null Block payload, or null when nothing matched
 */
function cyphtContextBlock($spec)
{
	global $db;

	// Each table's own element name, not a shared one: entities may differ.
	$where = $spec['where']." AND t.entity IN (".getEntity($spec['element']).")";

	$sql = "SELECT COUNT(t.rowid) as nb";
	if ($spec['amount'] !== '') {
		$sql .= ", SUM(t.".$spec['amount'].") as total";
	}
	$sql .= " FROM ".MAIN_DB_PREFIX.$spec['table']." as t";
	$sql .= " WHERE ".$where;

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog("cyphtWebmail context: ".$spec['key']." count failed: ".$db->lasterror(), LOG_ERR);
		return null;
	}
	$obj = $db->fetch_object($resql);
	$count = $obj ? (int) $obj->nb : 0;
	$total = ($obj && $spec['amount'] !== '') ? (float) $obj->total : 0;
	$db->free($resql);

	if ($count === 0) {
		return null;
	}

	$sql = "SELECT t.rowid, t.".$spec['ref']." as ref";
	if ($spec['date'] !== '') {
		$sql .= ", t.".$spec['date']." as dt";
	}
	if ($spec['amount'] !== '') {
		$sql .= ", t.".$spec['amount']." as amount";
	}
	$sql .= " FROM ".MAIN_DB_PREFIX.$spec['table']." as t";
	$sql .= " WHERE ".$where;
	$sql .= " ORDER BY ".($spec['date'] !== '' ? "t.".$spec['date']." DESC, " : "")."t.rowid DESC";
	$sql .= $db->plimit($spec['rows'], 0);

	$rows = array();
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog("cyphtWebmail context: ".$spec['key']." rows failed: ".$db->lasterror(), LOG_ERR);
		return null;
	}
	while ($obj = $db->fetch_object($resql)) {
		$rows[] = array(
			'ref' => (string) $obj->ref,
			'date' => ($spec['date'] !== '' ? cyphtContextDate($obj->dt) : ''),
			'amount' => ($spec['amount'] !== '' ? cyphtContextPrice($obj->amount) : ''),
			'url' => cyphtContextUrl($spec['card'], 'id='.((int) $obj->rowid)),
		);
	}
	$db->free($resql);

	return array(
		'key' => $spec['key'],
		'label' => $spec['label'],
		'icon' => $spec['icon'],
		'count' => $count,
		'total' => ($spec['amount'] !== '' ? cyphtContextPrice($total) : ''),
		'url' => $spec['list'],
		'rows' => $rows,
	);
}

// --- 4. Who is this address? ------------------------------------------

// Block headings use Dolibarr's own words for these records.
$langs->loadLangs(array('companies', 'commercial', 'bills', 'orders', 'propal', 'projects', 'ticket', 'members'));

$escaped = "'".$db->escape($email)."'";
$match = null;
$socid = 0;

// Same precedence bridge/contacts.php uses when de-duplicating on address.
$sql = "SELECT sp.rowid, sp.lastname, sp.firstname, sp.poste, sp.phone, sp.phone_mobile, sp.fk_soc";
$sql .= " FROM ".MAIN_DB_PREFIX."socpeople as sp";
$sql .= " WHERE sp.entity IN (".getEntity('contact').")";
$sql .= " AND sp.email = ".$escaped;
$sql .= " AND sp.statut = 1";
$sql .= " ORDER BY sp.rowid";
$sql .= $db->plimit(1, 0);

$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$socid = (int) $obj->fk_soc;
	$match = array(
		'type' => 'contact',
		'type_label' => $langs->trans('Contact'),
		'id' => (int) $obj->rowid,
		'name' => trim($obj->firstname.' '.$obj->lastname),
		'job' => (string) $obj->poste,
		'phone' => ($obj->phone ? (string) $obj->phone : (string) $obj->phone_mobile),
		'url' => cyphtContextUrl('/contact/card.php', 'id='.((int) $obj->rowid)),
	);
}
if ($resql) {
	$db->free($resql);
}

if ($match === null) {
	$sql = "SELECT s.rowid FROM ".MAIN_DB_PREFIX."societe as s";
	$sql .= " WHERE s.entity IN (".getEntity('societe').")";
	$sql .= " AND s.email = ".$escaped;
	$sql .= " AND s.status = 1";
	$sql .= " ORDER BY s.rowid";
	$sql .= $db->plimit(1, 0);

	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$socid = (int) $obj->rowid;
		// Name filled in from the third party block below.
		$match = array(
			'type' => 'thirdparty',
			'type_label' => $langs->trans('ThirdParty'),
			'id' => $socid,
			'name' => '',
			'job' => '',
			'phone' => '',
			'url' => cyphtContextUrl('/societe/card.php', 'socid='.$socid),
		);
	}
	if ($resql) {
		$db->free($resql);
	}
}

if ($match === null && getDolGlobalString('CYPHTWEBMAIL_CONTACTS_INCLUDE_USERS', 'true') === 'true') {
	$sql = "SELECT u.rowid, u.lastname, u.firstname, u.job, u.office_phone, u.user_mobile";
	$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
	$sql .= " WHERE u.entity IN (".getEntity('user').")";
	$sql .= " AND u.email = ".$escaped;
	$sql .= " AND u.statut = 1";
	$sql .= " ORDER BY u.rowid";
	$sql .= $db->plimit(1, 0);

	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$match = array(
			'type' => 'user',
			'type_label' => $langs->trans('User'),
			'id' => (int) $obj->rowid,
			'name' => trim($obj->firstname.' '.$obj->lastname),
			'job' => (string) $obj->job,
			'phone' => ($obj->office_phone ? (string) $obj->office_phone : (string) $obj->user_mobile),
			'url' => cyphtContextUrl('/user/card.php', 'id='.((int) $obj->rowid)),
		);
	}
	if ($resql) {
		$db->free($resql);
	}
}

if ($match === null && isModEnabled('member') && $bridgeUser->hasRight('adherent', 'lire')) {
	$sql = "SELECT a.rowid, a.lastname, a.firstname, a.societe, a.phone";
	$sql .= " FROM ".MAIN_DB_PREFIX."adherent as a";
	$sql .= " WHERE a.entity IN (".getEntity('adherent').")";
	$sql .= " AND a.email = ".$escaped;
	// Adherent::STATUS_VALIDATED, adherent.class.php:411.
	$sql .= " AND a.statut = 1";
	$sql .= " ORDER BY a.rowid";
	$sql .= $db->plimit(1, 0);

	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$match = array(
			'type' => 'member',
			'type_label' => $langs->trans('Member'),
			'id' => (int) $obj->rowid,
			'name' => trim($obj->firstname.' '.$obj->lastname),
			'job' => (string) $obj->societe,
			'phone' => (string) $obj->phone,
			'url' => cyphtContextUrl('/adherents/card.php', 'rowid='.((int) $obj->rowid)),
		);
	}
	if ($resql) {
		$db->free($resql);
	}
}

// Nobody owns this address. A body, not a 404: the panel still uses it.
if ($match === null) {
	cyphtContextRespond(200, array(
		'email' => $email,
		'match' => null,
		'thirdparty' => null,
		'blocks' => array(),
		'can_create' => $canCreate,
		'entity' => (int) $conf->entity,
	));
}

// --- 5. The third party behind them -----------------------------------

$thirdparty = null;

if ($socid > 0) {
	$sql = "SELECT s.rowid, s.nom, s.code_client, s.code_fournisseur, s.client, s.fournisseur,";
	$sql .= " s.town, s.phone, s.url as website";
	$sql .= " FROM ".MAIN_DB_PREFIX."societe as s";
	$sql .= " WHERE s.entity IN (".getEntity('societe').")";
	$sql .= " AND s.rowid = ".((int) $socid);

	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$thirdparty = array(
			'id' => (int) $obj->rowid,
			'name' => (string) $obj->nom,
			'code_client' => (string) $obj->code_client,
			'code_fournisseur' => (string) $obj->code_fournisseur,
			'is_customer' => ((int) $obj->client > 0),
			'is_supplier' => ((int) $obj->fournisseur > 0),
			'town' => (string) $obj->town,
			'phone' => (string) $obj->phone,
			'url' => cyphtContextUrl('/societe/card.php', 'socid='.((int) $obj->rowid)),
		);

		if ($match['type'] === 'thirdparty') {
			$match['name'] = $thirdparty['name'];
			$match['phone'] = $thirdparty['phone'];
		}
	} else {
		// Third party not visible in this entity. Drop the link.
		$socid = 0;
	}
	if ($resql) {
		$db->free($resql);
	}
}

// --- 6. What is open against them -------------------------------------

$blocks = array();
$rowLimit = getDolGlobalInt('CYPHTWEBMAIL_CONTEXT_ROWS', 3);
if ($rowLimit < 1) {
	$rowLimit = 1;
}
if ($rowLimit > 10) {
	$rowLimit = 10;
}

$blockSetting = getDolGlobalString('CYPHTWEBMAIL_CONTEXT_BLOCKS', 'propal,order,invoice,ticket,project');
$enabledBlocks = ($blockSetting === 'none')
	? array()
	: array_filter(array_map('trim', explode(',', $blockSetting)));

$invoiceScope = (getDolGlobalString('CYPHTWEBMAIL_CONTEXT_INVOICES', 'unpaid') === 'open') ? 'open' : 'unpaid';

if ($socid > 0) {
	$socClause = "t.fk_soc = ".((int) $socid);

	// Module keys and permission names differ; pairs from selectsearchbox.php.
	if (in_array('propal', $enabledBlocks, true) && isModEnabled('propal') && $bridgeUser->hasRight('propal', 'lire')) {
		$block = cyphtContextBlock(array(
			'key' => 'propal',
			'element' => 'propal',
			'label' => $langs->trans('Proposals'),
			'icon' => 'bi-file-earmark-text',
			'table' => 'propal',
			// 1 = validated and still open.
			'where' => $socClause." AND t.fk_statut = 1",
			'ref' => 'ref',
			'date' => 'datep',
			'amount' => 'total_ttc',
			'card' => '/comm/propal/card.php',
			'list' => cyphtContextUrl('/comm/propal/list.php', 'socid='.((int) $socid)),
			'rows' => $rowLimit,
		));
		if ($block !== null) {
			$blocks[] = $block;
		}
	}

	if (in_array('order', $enabledBlocks, true) && isModEnabled('order') && $bridgeUser->hasRight('commande', 'lire')) {
		$block = cyphtContextBlock(array(
			'key' => 'order',
			'element' => 'commande',
			'label' => $langs->trans('Orders'),
			'icon' => 'bi-bag',
			'table' => 'commande',
			// 1 = validated, 2 = in process. 3 is delivered, 0 draft, -1 cancelled.
			'where' => $socClause." AND t.fk_statut IN (1, 2)",
			'ref' => 'ref',
			'date' => 'date_commande',
			'amount' => 'total_ttc',
			'card' => '/commande/card.php',
			'list' => cyphtContextUrl('/commande/list.php', 'socid='.((int) $socid)),
			'rows' => $rowLimit,
		));
		if ($block !== null) {
			$blocks[] = $block;
		}
	}

	if (in_array('invoice', $enabledBlocks, true) && isModEnabled('invoice') && $bridgeUser->hasRight('facture', 'lire')) {
		$block = cyphtContextBlock(array(
			'key' => 'invoice',
			// 'invoice', not the table name: what getEntity() expects here.
			'element' => 'invoice',
			'label' => $langs->trans($invoiceScope === 'open' ? 'BillsCustomers' : 'BillsCustomersUnpaid'),
			'icon' => 'bi-receipt',
			'table' => 'facture',
			// Validated, and unsettled unless the setup page asks for all of them.
			'where' => $socClause." AND t.fk_statut = 1".($invoiceScope === 'open' ? '' : " AND t.paye = 0"),
			'ref' => 'ref',
			'date' => 'datef',
			'amount' => 'total_ttc',
			'card' => '/compta/facture/card.php',
			'list' => cyphtContextUrl('/compta/facture/list.php', 'socid='.((int) $socid)),
			'rows' => $rowLimit,
		));
		if ($block !== null) {
			$blocks[] = $block;
		}
	}

	if (in_array('ticket', $enabledBlocks, true) && isModEnabled('ticket') && $bridgeUser->hasRight('ticket', 'read')) {
		$block = cyphtContextBlock(array(
			'key' => 'ticket',
			'element' => 'ticket',
			'label' => $langs->trans('Tickets'),
			'icon' => 'bi-life-preserver',
			'table' => 'ticket',
			// Below Ticket::STATUS_CLOSED (8).
			'where' => $socClause." AND t.fk_statut < 8",
			'ref' => 'ref',
			'date' => 'datec',
			'amount' => '',
			'card' => '/ticket/card.php',
			'list' => cyphtContextUrl('/ticket/list.php', 'socid='.((int) $socid)),
			'rows' => $rowLimit,
		));
		if ($block !== null) {
			$blocks[] = $block;
		}
	}

	if (in_array('project', $enabledBlocks, true) && isModEnabled('project') && $bridgeUser->hasRight('projet', 'lire')) {
		$block = cyphtContextBlock(array(
			'key' => 'project',
			'element' => 'project',
			'label' => $langs->trans('Projects'),
			'icon' => 'bi-kanban',
			'table' => 'projet',
			// 1 = open. 0 is draft, 2 closed.
			'where' => $socClause." AND t.fk_statut = 1",
			'ref' => 'ref',
			'date' => 'datec',
			'amount' => '',
			'card' => '/projet/card.php',
			'list' => cyphtContextUrl('/projet/list.php', 'socid='.((int) $socid)),
			'rows' => $rowLimit,
		));
		if ($block !== null) {
			$blocks[] = $block;
		}
	}
}

cyphtContextRespond(200, array(
	'email' => $email,
	'match' => $match,
	'thirdparty' => $thirdparty,
	'blocks' => $blocks,
	'can_create' => $canCreate,
	'entity' => (int) $conf->entity,
));
