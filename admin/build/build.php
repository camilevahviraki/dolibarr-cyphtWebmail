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
 * \file        admin/build/build.php
 * \ingroup     cyphtWebmail
 * \brief       Build page: runs Cypht's config_gen.php through CyphtManager.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}

if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../../class/cyphtmanager.class.php';

global $conf, $db, $langs, $user;

$langs->loadLangs(array("admin", "cyphtWebmail@cyphtWebmail"));

// This endpoint kicks off a full composer install + Cypht build - same
// admin-only + CSRF checks as build_cancel.php, just missing here before.
if (!$user->admin) {
	http_response_code(403);
	exit;
}

// currentToken(), not newToken(): newToken() regenerates the session's
// stored token as a side effect of being called, so comparing against it
// here would rotate the token during the very check meant to verify it -
// guaranteeing a mismatch on every single request. currentToken() reads
// the value the form was actually rendered with, without changing it.
if (GETPOST('token', 'alpha') !== currentToken()) {
	http_response_code(403);
	echo "Invalid token";
	exit;
}

$manager = new CyphtManager($db);
$buildResult = null;

@set_time_limit(300);

if (session_id()) {
    session_write_close();
}

// One JSON object per line (NDJSON), not raw text: lets the client tell
// real stdout, real stderr, and our own synthetic status lines apart and
// color them differently, without needing a second channel (SSE/WebSocket)
// alongside this already-working chunked response. $chunk itself may
// contain literal newlines (e.g. multi-line composer output) - that's
// fine, json_encode() escapes them inside the JSON string, so each
// "line" of this response is still exactly one JSON object no matter
// what the underlying tool printed.
$buildResult = $manager->runConfigGen(function ($chunk, $type) use ($manager) {
    echo json_encode(array('t' => $type, 'c' => $chunk))."\n";
    $manager->cyphtwebmail_flush_now();
});

// Not reopening the session here: by this point output has already been
// streamed to the browser (that's the whole point), so headers are always
// already sent and session_start() would only ever produce a "Session
// cannot be started after headers have already been sent" warning that
// bleeds into the build log. Nothing after this point needs $_SESSION -
// this request just reports success/failure and ends; the next real page
// load starts its own session normally.

if (!$buildResult['success']) {
    // http_response_code() is a no-op here (headers went out with the
    // first streamed chunk above already) - kept anyway as an honest
    // reflection of the outcome for anything inspecting the response
    // object, even though the browser won't act on it at this point.
    http_response_code(500);
    // Same NDJSON envelope as every chunk above, not raw text - a plain
    // echo here would be a non-JSON line breaking the client's parser
    // right at the most important message (this build's final failure
    // reason), and 'err' colors it red like the failures already
    // streamed above it.
    echo json_encode(array(
        't' => 'err',
        'c' => "\n\n".$langs->trans("CyphtWebmailBuildFailed").": ".$buildResult['error'],
    ))."\n";
}
