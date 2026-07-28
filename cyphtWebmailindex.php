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
 * \file        cyphtWebmailindex.php
 * \ingroup     cyphtWebmail
 * \brief       Entry point reached from the top menu. For this POC there is
 *              no session bridging yet: Cypht shows its own login screen
 *              (IMAP_AUTH_SERVER configured on the setup page), the user
 *              logs in with their real mailbox credentials. Dolibarr's job
 *              here is just auth-gating access to the page and embedding
 *              the already-built Cypht app.
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
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("main.inc.php")) {
	$res = @include "main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once __DIR__.'/class/cyphtmanager.class.php';

global $conf, $db, $langs, $user;

$langs->loadLangs(array("cyphtWebmail@cyphtWebmail"));

// Module-level gate for this POC: any logged in user, as long as the module
// is enabled. No dedicated permission has been added yet (task for later,
// once we're past proving the end-to-end flow works).
if (!isModEnabled('cyphtwebmail')) {
	accessforbidden();
}

$manager = new CyphtManager($db);

llxHeader('', $langs->trans("CyphtWebmailArea"), '', '', 0, 0, '', '', '', 'mod-cyphtwebmail page-index');

if (!$manager->isPublished()) {
	print '<div class="warning" style="padding: 15px;">';
	print $langs->trans("CyphtWebmailNotYetBuilt");
	print ' <a href="'.dol_buildpath('/cyphtWebmail/admin/setup.php', 1).'">';
	print $langs->trans("CyphtWebmailGoToSetup");
	print '</a>';
	print '</div>';
} else {
	$publicUrl = dol_buildpath('/cyphtWebmail/public/index.php', 1);
	print '<iframe src="'.dol_escape_htmltag($publicUrl).'" '.
		'style="width:100%; height: calc(100vh - 220px); min-height: 500px; border: none;" '.
		'title="Cypht Webmail"></iframe>';
}

llxFooter();
$db->close();
