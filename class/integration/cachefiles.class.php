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
 * \file        class/integration/cachefiles.class.php
 * \ingroup     cyphtWebmail
 * \brief       Where the files Dolibarr publishes for the webmail live.
 *
 *              Both halves of the module need these names: the Dolibarr
 *              side to write, the Cypht side to read. They run in separate
 *              requests and share no bootstrap, so this file is written to
 *              be loadable from either, and deliberately depends on nothing
 *              at all: no Dolibarr functions, no Cypht classes, no globals.
 *
 *              The Cypht side finds it through DOLIBARR_MODULE_ROOT, which
 *              CyphtEnvBootstrap exports.
 */
class CyphtCacheFiles
{
	/**
	 * A login reduced to characters that are safe in a file name.
	 *
	 * @param string $login
	 * @return string
	 */
	public static function key($login)
	{
		return preg_replace('/[^A-Za-z0-9_.@-]/', '_', (string) $login);
	}

	/**
	 * @param string $dir   Cache directory, DOLIBARR_CACHE_DIR
	 * @param string $login
	 * @param string $kind  'contacts', 'mail-templates'
	 * @return string
	 */
	public static function fileFor($dir, $login, $kind)
	{
		return rtrim((string) $dir, '/\\') . '/' . self::key($login) . '-' . $kind . '.json';
	}

	/**
	 * Decode one cache file, for the Cypht half.
	 *
	 * @param string $dir
	 * @param string $login
	 * @param string $kind
	 * @param string $expect Key the payload must carry
	 * @return array<string,mixed>|false
	 */
	public static function read($dir, $login, $kind, $expect)
	{
		if ((string) $dir === '') {
			return false;
		}

		$file = self::fileFor($dir, $login, $kind);
		if (!is_readable($file)) {
			return false;
		}

		$data = json_decode((string) @file_get_contents($file), true);
		if (!is_array($data) || !isset($data[$expect]) || !is_array($data[$expect])) {
			return false;
		}

		return $data;
	}
}
