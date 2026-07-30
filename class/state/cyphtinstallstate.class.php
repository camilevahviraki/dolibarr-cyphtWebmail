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
 * \file        class/state/cyphtinstallstate.class.php
 * \ingroup     cyphtWebmail
 * \brief       Path resolution and installed/built-version bookkeeping for
 *              the vendored Cypht app.
 */
class CyphtInstallState
{
	/**
	 * Absolute path to the module root (three levels up from this file:
	 * class/state/X.php -> class/state -> class -> module root).
	 *
	 * @return string
	 */
	public function getModuleRoot()
	{
		return dirname(__DIR__, 2);
	}

	/**
	 * Absolute path to the vendored Cypht application (composer package root).
	 *
	 * @return string
	 */
	public function getCyphtPath()
	{
		return $this->getModuleRoot() . '/vendor/jason-munro/cypht';
	}

	/**
	 * Absolute path to the directory config_gen.php publishes its production
	 * site/ output to (inside the vendored Cypht package itself).
	 *
	 * @return string
	 */
	public function getCyphtSitePath()
	{
		return $this->getCyphtPath() . '/site';
	}

	/**
	 * Absolute path to the module's own public/ directory, which is the
	 * folder we copy the built Cypht site/ into so it sits somewhere the
	 * webserver can serve directly (vendor/ should never be web-exposed).
	 *
	 * @return string
	 */
	public function getPublicPath()
	{
		return $this->getModuleRoot() . '/public';
	}

	/**
	 * Dolibarr-managed data directory for this module (outside web root,
	 * created at module activation via $this->dirs in the descriptor).
	 * Ensures the "users" and "attachments" subfolders Cypht needs exist.
	 *
	 * @return string
	 */
	public function getDataDir()
	{
		$dir = DOL_DATA_ROOT . '/cyphtWebmail';

		require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
		dol_mkdir($dir . '/users');
		dol_mkdir($dir . '/attachments');

		return $dir;
	}

	/**
	 * Read jason-munro/cypht's installed version straight from Composer's
	 * own installed.json, so this always reflects whatever is actually on
	 * disk (post composer update) rather than something we cached earlier.
	 *
	 * @return string|null  Version string (e.g. "2.11.1") or null if unknown
	 */
	public function getInstalledVersion()
	{
		$installedJson = $this->getModuleRoot() . '/vendor/composer/installed.json';
		if (!file_exists($installedJson)) {
			return null;
		}

		$data = json_decode(file_get_contents($installedJson), true);
		if (!is_array($data)) {
			return null;
		}
		// Composer 2 wraps the package list in a "packages" key; composer 1 was a flat array.
		$packages = isset($data['packages']) ? $data['packages'] : $data;

		foreach ($packages as $pkg) {
			if (isset($pkg['name']) && $pkg['name'] === 'jason-munro/cypht') {
				return ltrim($pkg['version'], 'v');
			}
		}

		return null;
	}

	/**
	 * Version that was actually built last time the button was clicked
	 * (stored in llx_const after a successful runConfigGen()).
	 *
	 * @return string
	 */
	public function getBuiltVersion()
	{
		return getDolGlobalString('CYPHTWEBMAIL_BUILT_VERSION', '');
	}

	/**
	 * Timestamp of the last successful build, or empty string if never built.
	 *
	 * @return string
	 */
	public function getLastBuildDate()
	{
		return getDolGlobalString('CYPHTWEBMAIL_LAST_BUILD', '');
	}

	/**
	 * True if the vendored Cypht version on disk differs from the version
	 * we last generated a config for (e.g. after "composer update" pulled
	 * a newer jason-munro/cypht release into vendor/).
	 *
	 * @return bool
	 */
	public function needsRebuild()
	{
		$installed = $this->getInstalledVersion();
		if ($installed === null) {
			return false; // Cypht isn't even installed, nothing to rebuild
		}
		return ($installed !== $this->getBuiltVersion());
	}

	/**
	 * True if a build has ever succeeded and its output was published.
	 *
	 * @return bool
	 */
	public function isPublished()
	{
		return file_exists($this->getPublicPath() . '/index.php');
	}
}
