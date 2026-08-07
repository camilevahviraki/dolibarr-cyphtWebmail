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
 * \brief       Entry point reached from the top menu. Logs the current
 *              Dolibarr user into Cypht via SSO (see
 *              CyphtManager::performSsoLogin()) before embedding the
 *              already-built app, so the iframe opens already authenticated.
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

// Current Cypht page, carried in one opaque parameter holding Cypht's own
// query string. Nested rather than mirrored because Cypht uses page/id/uid
// and Dolibarr uses action/id/token: merging the namespaces collides on "id".
// Whitelisted here because it ends up in an iframe src.
$cyphtQuery = GETPOST('cypht', 'none');
if (!is_string($cyphtQuery) || !preg_match('/^[A-Za-z0-9_\-\.=&%+]{0,300}$/', $cyphtQuery)) {
	$cyphtQuery = '';
}

// Must happen before any HTML output (llxHeader() included): SSO login
// sets Cypht's hm_id/hm_session cookies via setcookie(), which silently
// fails once headers have already been sent.
$ssoOk = false;
$publicUrl = '';
if ($manager->isPublished()) {
	$publicUrl = dol_buildpath('/cyphtWebmail/public/index.php', 1);
	$ssoOk = $manager->performSsoLogin($user->login, $publicUrl);
}

llxHeader('', $langs->trans("CyphtWebmailArea"), '', '', 0, 0, '', '', '', 'mod-cyphtwebmail page-index');

if (!$manager->isPublished()) {
	print '<div class="warning" style="padding: 15px;">';
	print $langs->trans("CyphtWebmailNotYetBuilt");
	print ' <a href="'.dol_buildpath('/cyphtWebmail/admin/setup.php', 1).'">';
	print $langs->trans("CyphtWebmailGoToSetup");
	print '</a>';
	print '</div>';
} else {
	if (!$ssoOk && $manager->error) {
		// Non-fatal: fall back to Cypht's own login screen rather than
		// blocking access to the page entirely.
		print '<div class="warning" style="padding: 15px;">'.dol_escape_htmltag($manager->error).'</div>';
	}
	// SSO is still passed the bare $publicUrl: it parses it for the cookie
	// domain/path, where a query string has no place.
	$frameUrl = $publicUrl.($cyphtQuery !== '' ? '?'.$cyphtQuery : '');

	print '<iframe id="cyphtwebmail-frame" src="'.dol_escape_htmltag($frameUrl).'" '.
		'style="width:100%; height: calc(100vh - 220px); min-height: 500px; border: none;" '.
		'title="Cypht Webmail"></iframe>';

	// Mirror the frame's location into this page's URL so a reload returns to
	// the same Cypht page. Polled, not driven by the frame's load event:
	// Cypht is a single page app and its router uses history.pushState (see
	// modules/core/navigation/navigation.js), which fires no event a parent
	// document can observe. Same origin, so the location is readable directly.
	print '<script type="text/javascript">
(function () {
	var frame = document.getElementById("cyphtwebmail-frame");
	if (!frame || !window.history || !window.history.replaceState) {
		return;
	}
	var last = null;
	function sync() {
		var query;
		try {
			query = frame.contentWindow.location.search.replace(/^\?/, "");
		} catch (e) {
			return;
		}
		if (query === last) {
			return;
		}
		last = query;
		var url = new URL(window.location.href);
		if (query) {
			url.searchParams.set("cypht", query);
		} else {
			url.searchParams.delete("cypht");
		}
		window.history.replaceState(null, "", url.toString());
	}
	frame.addEventListener("load", sync);
	setInterval(sync, 400);
})();
</script>';
}

llxFooter();
$db->close();
