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

require_once __DIR__ . '/filecache.class.php';

/**
 * \file        class/integration/mailtemplatecache.class.php
 * \ingroup     cyphtWebmail
 * \brief       Publishes the email templates for the webmail. See
 *              CyphtFileCache for why this crosses on disk.
 */
class CyphtMailTemplateCache extends CyphtFileCache
{
	const KIND = 'mail-templates';

	/**
	 * Rewrite this user's file when it is missing or older than the TTL.
	 *
	 * Templates are per user, not per install: the query hides other
	 * people's private ones, and labels are resolved in $langs, which
	 * follows whoever is logged in.
	 *
	 * @param DoliDB    $db
	 * @param User      $user
	 * @param Translate $langs
	 * @param bool      $force Ignore the TTL
	 * @return bool False on failure, with error set
	 */
	public function refresh($db, $user, $langs, $force = false)
	{
		$file = $this->fileFor($user->login, self::KIND);

		if (!$force && $this->isFresh($file, getDolGlobalInt('CYPHTWEBMAIL_MAIL_TEMPLATES_TTL', 900))) {
			return true;
		}

		require_once __DIR__ . '/mailtemplatecollector.class.php';

		$error = '';
		$data = CyphtMailTemplateCollector::collect($db, $user, $langs, $error);
		if ($data === null) {
			$this->error = $error;
			return false;
		}

		$data['login'] = $user->login;
		$data['written_at'] = time();

		return $this->put($file, $data);
	}
}
