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
 * \file        class/integration/filecache.class.php
 * \ingroup     cyphtWebmail
 * \brief       Base for the files Dolibarr publishes for the webmail.
 *
 *              The module is two applications, not one. Dolibarr serves
 *              index.php and admin/; the vendored Cypht app serves public/
 *              and runs in its own request with its own bootstrap. Neither
 *              can call the other's API: Cypht has no $db, no $user and no
 *              Dolibarr functions, and loading main.inc.php inside it would
 *              collide with Cypht's own globals and session.
 *
 *              So anything Dolibarr knows has to be handed over. Both run
 *              as one user on one machine, so the cheapest channel is a
 *              file: Dolibarr writes it while serving its own page, where
 *              $db and $user are already at hand, and Cypht reads it while
 *              rendering.
 *
 *              The bridge/ endpoints do the same job over HTTP, for data
 *              that cannot be known before the request. That only works
 *              when nothing sits in front of Dolibarr, since the server
 *              must reach its own public URL, so prefer a cache wherever
 *              the answer can be prepared in advance.
 */
class CyphtFileCache
{
	/** @var CyphtPaths */
	protected $paths;

	/** @var string Set when a write fails */
	public $error = '';

	/**
	 * @param CyphtPaths $paths
	 */
	public function __construct(CyphtPaths $paths)
	{
		$this->paths = $paths;
	}

	/**
	 * @return string
	 */
	public function dir()
	{
		return $this->paths->getDataDir() . '/cache';
	}

	/**
	 * @param string $login
	 * @param string $kind
	 * @return string
	 */
	public function fileFor($login, $kind)
	{
		require_once __DIR__ . '/cachefiles.class.php';

		return CyphtCacheFiles::fileFor($this->dir(), $login, $kind);
	}

	/**
	 * @param string $file
	 * @param int    $ttl Seconds
	 * @return bool
	 */
	protected function isFresh($file, $ttl)
	{
		if (!is_readable($file)) {
			return false;
		}

		$age = time() - (int) @filemtime($file);

		return ($age >= 0 && $age < $ttl);
	}

	/**
	 * Write to a temp file then rename, so the reader never sees a
	 * half written file.
	 *
	 * @param string $file
	 * @param array<string,mixed> $data
	 * @return bool
	 */
	protected function put($file, array $data)
	{
		$dir = dirname($file);
		if (!is_dir($dir)) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
			dol_mkdir($dir);
		}
		if (!is_dir($dir)) {
			$this->error = 'Could not create ' . $dir;
			return false;
		}

		$json = json_encode($data);
		if ($json === false) {
			$this->error = 'Could not encode the cache payload: ' . json_last_error_msg();
			return false;
		}

		$tmp = $file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, $json) === false) {
			$this->error = 'Could not write ' . $tmp;
			return false;
		}
		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			$this->error = 'Could not replace ' . $file;
			return false;
		}

		@chmod($file, 0600);

		return true;
	}
}
