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

require_once __DIR__ . '/../state/cyphtinstallstate.class.php';

/**
 * \file        class/cypht/cyphtmoduleinstaller.class.php
 * \ingroup     cyphtWebmail
 * \brief       Installs this module's Cypht module sets into the vendored
 *              Cypht application.
 *
 *              Sources live in cypht/modules/<name>, laid out exactly like a
 *              native Cypht module set, and are copied into
 *              vendor/jason-munro/cypht/modules/<name> on every build, since
 *              Composer re-extracts that directory whenever the locked
 *              version changes.
 *
 *              Files are discovered rather than listed, so adding a module
 *              set, or a file to one, needs no change here. Existing files
 *              are overwritten and anything else in the destination is left
 *              alone, which is what lets "site" override one file of a module
 *              set Cypht already ships.
 */
class CyphtModuleInstaller
{
	/**
	 * @var string  Last error message, if any call returned false/failure.
	 */
	public $error = '';

	/**
	 * @var CyphtInstallState
	 */
	private $paths;

	/**
	 * @param CyphtInstallState $paths
	 */
	public function __construct(CyphtInstallState $paths)
	{
		$this->paths = $paths;
	}

	/**
	 * Directory holding this module's Cypht module sets.
	 *
	 * @return string
	 */
	public function getSourceRoot()
	{
		return $this->paths->getModuleRoot() . '/cypht/modules';
	}

	/**
	 * Names of every module set shipped by this module.
	 *
	 * @return string[]
	 */
	public function listModuleSets()
	{
		$dirs = glob($this->getSourceRoot() . '/*', GLOB_ONLYDIR);
		if (!is_array($dirs)) {
			return array();
		}

		return array_map('basename', $dirs);
	}

	/**
	 * Install one module set.
	 *
	 * @param string $name Module set name, e.g. "dolibarr_contacts"
	 * @return bool
	 */
	public function install($name)
	{
		$name = basename($name);
		$source = $this->getSourceRoot() . '/' . $name;
		$target = $this->paths->getCyphtPath() . '/modules/' . $name;

		if (!is_dir($source)) {
			$this->error = 'No such module set: ' . $source;
			return false;
		}

		if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
			$this->error = 'Could not create ' . $target;
			return false;
		}

		$files = glob($source . '/*');
		if (!is_array($files) || count($files) === 0) {
			$this->error = 'Module set is empty: ' . $source;
			return false;
		}

		foreach ($files as $file) {
			if (is_dir($file)) {
				continue;
			}
			if (!@copy($file, $target . '/' . basename($file))) {
				$this->error = 'Could not write ' . $target . '/' . basename($file);
				return false;
			}
		}

		return true;
	}

	/**
	 * Install every native like cypht module set shipped by this webmail-module.
	 *
	 * @return bool
	 */
	public function installAll()
	{
		$names = $this->listModuleSets();
		if (count($names) === 0) {
			$this->error = 'No module sets found under ' . $this->getSourceRoot();
			return false;
		}

		foreach ($names as $name) {
			if (!$this->install($name)) {
				return false;
			}
		}

		return true;
	}
}
