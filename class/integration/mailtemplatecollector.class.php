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
 * \file        class/integration/mailtemplatecollector.class.php
 * \ingroup     cyphtWebmail
 * \brief       Reads the email templates out of Dolibarr.
 *
 *              Templates live in llx_c_email_templates. Their labels are
 *              often translation keys rather than text, and their bodies
 *              carry __MARKERS__, so both are resolved here: labels through
 *              $langs, markers through the object-free substitution array.
 *              Markers that need an object cannot resolve at compose time
 *              and are reported in 'placeholders' instead of stripped.
 *
 *              Two callers need this, so it lives apart from both: the
 *              cache writer and bridge/mail_templates.php.
 *
 *              Preconditions: Dolibarr is loaded, $user has had
 *              loadRights() called, and $conf->entity matches $user.
 */
class CyphtMailTemplateCollector
{
	/**
	 * Every active template this user may see.
	 *
	 * @param DoliDB    $db
	 * @param User      $user
	 * @param Translate $langs
	 * @param string    $error Set when null is returned
	 * @return array<string,mixed>|null Keys: templates, types, count, entity, hint
	 */
	public static function collect($db, $user, $langs, &$error = '')
	{
		global $conf;

		$langs->loadLangs(array('errors', 'admin', 'mails', 'other'));

		// Every active template this user may see, of every type. Ordered by type so
		// the grouping in the picker follows the query rather than being re-sorted.
		$sql = "SELECT rowid, label, type_template, module, lang, position, topic, content,";
		$sql .= " email_from, email_to, email_tocc, email_tobcc";
		$sql .= " FROM ".MAIN_DB_PREFIX."c_email_templates";
		$sql .= " WHERE entity IN (".getEntity('c_email_templates').")";
		$sql .= " AND active = 1";
		// The access control. Copied from FormMail::getEMailTemplate().
		$sql .= " AND (private = 0 OR fk_user = ".((int) $user->id).")";
		$sql .= " ORDER BY type_template ASC, position ASC, label ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			$error = 'Template query failed: '.$db->lasterror();
			return null;
		}

		// Resolves only object-free markers. Anything written around an object, such
		// as __TICKET_URL__, cannot resolve here because compose has no such object;
		// those are reported in 'placeholders' rather than stripped.
		$substitutions = getCommonSubstitutionArray($langs, 0, null, null);

		$templates = array();
		$types = array();
		$rows = array();
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
			/* The row says which module seeded it, so load that module's
			 * lang file rather than guessing from a list. load() is quiet
			 * about domains that do not exist. */
			if (!empty($obj->module)) {
				$langs->load((string) $obj->module);
			}
		}
		$db->free($resql);

		foreach ($rows as $obj) {
			$type = (string) $obj->type_template;
			$subject = make_substitutions((string) $obj->topic, $substitutions, $langs);
			$body = make_substitutions((string) $obj->content, $substitutions, $langs);

			// What survived substitution, so the compose screen can warn before the
			// user sends an email containing a literal __SOMETHING__.
			$leftover = array();
			if (preg_match_all('/__[A-Z0-9_]+__/', $subject.' '.$body, $m)) {
				$leftover = array_values(array_unique($m[0]));
			}

			$templates[] = array(
				'id'      => (int) $obj->rowid,
				'label'   => self::label((string) $obj->label, $langs),
				'lang'    => (string) $obj->lang,
				'type'    => $type,
				'type_label' => self::typeLabel($type, $langs),
				'subject' => $subject,
				'body'    => $body,
				'placeholders' => $leftover,
				'from'    => (string) $obj->email_from,
				'to'      => (string) $obj->email_to,
				'cc'      => (string) $obj->email_tocc,
				'bcc'     => (string) $obj->email_tobcc,
			);

			if (!isset($types[$type])) {
				$types[$type] = array(
					'type'  => $type,
					'label' => self::typeLabel($type, $langs),
					'count' => 0,
				);
			}
			$types[$type]['count']++;
		}

		return array(
			'templates' => $templates,
			'types' => array_values($types),
			'count' => count($templates),
			'entity' => (int) $conf->entity,
			/* Legitimately empty on a fresh install, which reads as a fault
			 * from the Cypht side. Say so instead. */
			'hint' => (count($templates) === 0
				? 'No email templates yet. Create one in Dolibarr under Home, Setup, Emails, Email templates.'
				: ''),
		);
	}

	/**
	 * Resolve a template label for display.
	 *
	 * Labels like '(SendingAdminEmailMessage)' are translation keys. Rule copied
	 * from FormMail::getEMailTemplate(), html.formmail.class.php:597.
	 *
	 * Keys with no en_US translation are split on capitals rather than shown raw
	 * as core does.
	 *
	 * @param string $label Raw label column
	 * @param Translate $langs
	 * @return string
	 */
	private static function label($label, $langs)
	{
		$label = trim($label);
		if (!preg_match('/\((.*)\)/', $label, $reg)) {
			return $label;
		}

		$key = $reg[1];
		$translated = $langs->trans($key);
		// trans() echoes the key back when there is no translation for it.
		if ($translated !== $key) {
			return $translated;
		}

		$spaced = preg_replace('/(?<!^)([A-Z])/', ' $1', $key);

		return ucfirst(strtolower($spaced));
	}

	/**
	 * Human label for a type_template token.
	 *
	 * type_template is a free varchar with no dictionary table behind it,
	 * and any module may insert its own, so the label is resolved rather
	 * than looked up in a list that would go stale.
	 *
	 * getElementProperties() is core's own token parser: it splits
	 * 'invoice_supplier_send' into module and element the same way the rest
	 * of Dolibarr does. The element name is then a translation key in the
	 * module's own lang file, already loaded above from the module column.
	 *
	 * @param string $token type_template value
	 * @param Translate $langs
	 * @return string
	 */
	private static function typeLabel($token, $langs)
	{
		$token = (string) $token;
		if ($token === '' || $token === 'all' || $token === 'none') {
			return $langs->trans($token === 'none' ? 'None' : 'All');
		}

		/* '_send' is a suffix on the template type, not part of the element. */
		$element = preg_replace('/_send$/', '', $token);

		$candidates = array($element);
		if (function_exists('getElementProperties')) {
			$props = getElementProperties($element);
			foreach (array('element', 'module') as $key) {
				if (!empty($props[$key]) && !in_array($props[$key], $candidates, true)) {
					$candidates[] = $props[$key];
				}
			}
		}

		foreach ($candidates as $key) {
			$translated = $langs->trans(ucfirst($key));
			/* trans() echoes the key back when there is no translation. */
			if ($translated !== ucfirst($key)) {
				return $translated;
			}
		}

		return ucfirst(str_replace('_', ' ', $token));
	}
}
