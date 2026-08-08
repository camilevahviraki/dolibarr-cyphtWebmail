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
 * \brief       Build page: runs Cypht's config_gen.php through CyphtWebmail.
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
require_once __DIR__.'/../../class/webmail.class.php';

global $conf, $db, $langs, $user;

$langs->loadLangs(array("admin", "cyphtWebmail@cyphtWebmail"));

if (!$user->admin) {
	http_response_code(403);
	exit;
}

// currentToken(), not newToken(): newToken() rotates the session token
// as a side effect, which would break this check on every request.
if (GETPOST('token', 'alpha') !== currentToken()) {
	http_response_code(403);
	echo "Invalid token";
	exit;
}

$manager = new CyphtWebmail($db);
$buildResult = null;

@set_time_limit(300);

if (session_id()) {
    session_write_close();
}

// NDJSON, one {t,c} object per line, so the client can color output by type.
$buildResult = $manager->runConfigGen(function ($chunk, $type) use ($manager) {
    echo json_encode(array('t' => $type, 'c' => $chunk))."\n";
    $manager->cyphtwebmail_flush_now();
});

if (!$buildResult['success']) {
    http_response_code(500); // no-op once headers are sent, kept for correctness
    echo json_encode(array(
        't' => 'err',
        'c' => "\n\n".$langs->trans("CyphtWebmailBuildFailed").": ".$buildResult['error'],
    ))."\n";
}
