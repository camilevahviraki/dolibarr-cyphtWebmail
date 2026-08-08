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
 * \file        admin/setup.php
 * \ingroup     cyphtWebmail
 * \brief       Setup page: IMAP settings + the "Generate / Rebuild" button
 *              that runs Cypht's config_gen.php through CyphtWebmail.
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

if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../class/webmail.class.php';

global $conf, $db, $langs, $user;

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "cyphtWebmail@cyphtWebmail"));

$action = GETPOST('action', 'aZ09');
$manager = new CyphtWebmail($db);
$buildResult = null;

if ($action == 'update_settings') {
	dolibarr_set_const($db, 'CYPHTWEBMAIL_IMAP_NAME', GETPOST('imap_name', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CYPHTWEBMAIL_IMAP_SERVER', GETPOST('imap_server', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CYPHTWEBMAIL_IMAP_PORT', GETPOST('imap_port', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CYPHTWEBMAIL_IMAP_TLS', (GETPOST('imap_tls', 'alpha') ? 'true' : 'false'), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans("SetupSaved"), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$form = new Form($db);
$manager = new CyphtWebmail($db);
// Computed once and reused for every form/JS call on this page.
$formToken = newToken();

llxHeader('', $langs->trans("CyphtWebmailSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("CyphtWebmailSetup"), $linkback, 'title_setup');

$head = array();
$head[0][0] = $_SERVER["PHP_SELF"];
$head[0][1] = $langs->trans("Settings");
$head[0][2] = 'settings';

print dol_get_fiche_head($head, 'settings', '', -1);

// ---- IMAP settings form ----
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.$formToken.'">';
print '<input type="hidden" name="action" value="update_settings">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("CyphtWebmailImapName").'</td><td>';
print '<input type="text" class="flat minwidth300" name="imap_name" value="'.dol_escape_htmltag(getDolGlobalString('CYPHTWEBMAIL_IMAP_NAME', 'Webmail')).'">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("CyphtWebmailImapServer").'</td><td>';
print '<input type="text" class="flat minwidth300" name="imap_server" value="'.dol_escape_htmltag(getDolGlobalString('CYPHTWEBMAIL_IMAP_SERVER', 'localhost')).'" placeholder="imap.example.com">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("CyphtWebmailImapPort").'</td><td>';
print '<input type="text" class="flat width75" name="imap_port" value="'.dol_escape_htmltag(getDolGlobalString('CYPHTWEBMAIL_IMAP_PORT', '993')).'">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("CyphtWebmailImapTls").'</td><td>';
print '<input type="checkbox" name="imap_tls" value="1"'.(getDolGlobalString('CYPHTWEBMAIL_IMAP_TLS', 'true') == 'true' ? ' checked' : '').'>';
print '</td></tr>';

print '</table>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

print dol_get_fiche_end();


$manager->cyphtwebmail_flush_now();

print load_fiche_titre($langs->trans("CyphtWebmailBuildStatus"), '', '');

print '<table class="noborder centpercent">';

print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("CyphtWebmailInstalledVersion").'</td><td>';
$installedVersion = $manager->getInstalledVersion();
print $installedVersion ? dol_escape_htmltag($installedVersion) : '<span class="error">'.$langs->trans("CyphtWebmailNotInstalled").'</span>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("CyphtWebmailBuiltVersion").'</td><td>';
$builtVersion = $manager->getBuiltVersion();
print $builtVersion ? dol_escape_htmltag($builtVersion) : $langs->trans("CyphtWebmailNeverBuilt");
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("CyphtWebmailLastBuild").'</td><td>';
$lastBuild = $manager->getLastBuildDate();
print $lastBuild ? dol_print_date($lastBuild, 'dayhour') : '-';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("Status").'</td><td>';
if (!$installedVersion) {
	print '<span class="error">'.$langs->trans("CyphtWebmailNotInstalled").'</span>';
} elseif ($manager->needsRebuild()) {
	print '<span class="warning">'.$langs->trans("CyphtWebmailUpdateAvailable", $installedVersion).'</span>';
} elseif ($manager->isPublished()) {
	print '<span class="ok">'.$langs->trans("CyphtWebmailUpToDate").'</span>';
} else {
	print $langs->trans("CyphtWebmailNeverBuilt");
}
print '</td></tr>';

print '</table>';

$requirements = $manager->checkBuildRequirements();

if (!$requirements['ok']) {
	print '<div class="warning" style="padding: 12px; margin-top: 10px;">';
	print '<strong>'.$langs->trans("CyphtWebmailCannotBuildHere").'</strong><br><br>';

	print '<table class="nobordernopadding">';
	foreach ($requirements['checks'] as $check) {
		print '<tr><td style="padding-right: 10px;">';
		print $check['ok'] ? img_picto('', 'tick') : img_picto('', 'error');
		print '</td><td style="padding-right: 10px;">'.dol_escape_htmltag($check['label']).'</td>';
		print '<td class="opacitymedium">'.dol_escape_htmltag($check['detail']).'</td></tr>';
	}
	print '</table><br>';

	print $langs->trans("CyphtWebmailBuildFromShell").'<br>';
	print '<pre style="margin-top: 8px; padding: 8px; background: #f4f4f4; overflow-x: auto;">';
	print 'cd '.dol_escape_htmltag($manager->getModuleRoot())."\n";
	print 'php scripts/build.php';
	print '</pre>';
	print '</div>';
} else {
	print '<div class="center" style="margin-top: 10px;">';
	print '<form id="cypht-build-form">';
	print '<input type="hidden" name="token" value="'.$formToken.'">';
	print '<input type="hidden" name="action" value="build">';
	print '<button type="submit" class="button" data-loading-text="'.$langs->trans("CyphtWebmailBuilding").'">'.$langs->trans("CyphtWebmailGenerateButton").'</button>';
	print '</form>';
	print '</div>';
}

// 'out' = real child-process output, 'err' = our own failure messages,
// 'info' = our own step/status lines. Stderr is not colored red just for
// being stderr; see CyphtPipeline::runProcess().
print '<style>
#cyphtwebmail-log .log-out { color: #d4d4d4; }
#cyphtwebmail-log .log-err { color: #f14c4c; }
#cyphtwebmail-log .log-info { color: #4fc1ff; font-weight: bold; }
</style>';
$lastBuildLog = $manager->getLastBuildLog();

// Hidden if no build has ever run; setup.js un-hides it once one starts.
print '<div class="center" style="margin-top: 10px;">';
print '<button type="button" id="cyphtwebmail-log-toggle" class="button" data-show-text="'.$langs->trans("CyphtWebmailShowLog").'" data-hide-text="'.$langs->trans("CyphtWebmailHideLog").'" style="'.($lastBuildLog === '' ? 'display:none;' : '').'">'.$langs->trans("CyphtWebmailShowLog").'</button>';
print '</div>';
print '<div id="cyphtwebmail-log-wrap" style="display:none;">';
print '<pre id="cyphtwebmail-log" style="background:#1e1e1e; color:#d4d4d4; font-family:Consolas,\'Courier New\',monospace; font-size:12px; line-height:1.5; padding:12px 14px; max-height:500px; overflow:auto; border-radius:6px; border:1px solid #333; white-space:pre-wrap; word-break:break-all; margin-top:10px;"></pre>';
print '</div>';

// json_encode() escapes "/" to "\/", preventing a literal "</script>"
// in the log from closing this tag early.
print '<script type="application/json" id="cyphtwebmail-last-log">'.json_encode($lastBuildLog).'</script>';

print '<script src="'.dol_buildpath('/cyphtWebmail/js/admin/setup.js', 1).'"></script>';

llxFooter();
$db->close();
