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
 * \file        class/upstream/cyphtupstreampatcher.class.php
 * \ingroup     cyphtWebmail
 * \brief       Patches a genuine upstream Cypht bug that only surfaces once
 *              a module's functions.php gets require()'d twice in the same
 *              process, which performSsoLogin()'s "functional login" call
 *              does. Extracted out of CyphtManager, which had grown too
 *              large - see class/cyphtmanager.class.php for the facade
 *              that wires this together with its siblings.
 */
class CyphtUpstreamPatcher
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
	 * Most functions in modules/core/functions.php are wrapped in
	 * "if (!hm_exists('name')) { function name(...) {...} }" so the file
	 * survives being require()'d more than once in the same process - but
	 * a handful are missing that guard (found by scanning the file:
	 * get_special_folders, privacy_setting_callback,
	 * getSettingsSectionOutput, isPageConfigured as of Cypht 2.11.1).
	 * Harmless normally, because nothing used to load Cypht's modules
	 * twice in one PHP process - but performSsoLogin()'s "functional
	 * login" call (modules/api_login/api.php) does exactly that, causing
	 * a fatal "Cannot redeclare ...()" the moment SSO is used.
	 *
	 * Rather than hardcode that specific list (fragile - the exact set
	 * could shift on a future Cypht release), this scans the file with
	 * PHP's own tokenizer and wraps *every* top-level unguarded function
	 * the same way its already-guarded neighbors are, skipping anything
	 * already wrapped so repeat builds stay idempotent.
	 *
	 * Applied by the build process (like CyphtSsoBridge::writeSiteAuthOverride()
	 * is) rather than hand-edited in vendor/, so "composer install"
	 * re-fetching this package never silently reverts it.
	 *
	 * @return bool
	 */
	public function patchCoreFunctionsGuard()
	{
		$path = $this->paths->getCyphtPath() . '/modules/core/functions.php';
		if (!is_readable($path)) {
			return true; // nothing to patch yet - composer install would have already failed if this matters
		}

		$content = file_get_contents($path);
		if ($content === false) {
			$this->error = 'Could not read ' . $path;
			return false;
		}

		$patched = $this->wrapUnguardedTopLevelFunctions($content);
		if ($patched === $content) {
			return true; // nothing unguarded found (or already patched)
		}

		if (file_put_contents($path, $patched) === false) {
			$this->error = 'Could not write ' . $path;
			return false;
		}

		return true;
	}

	/**
	 * Wrap every top-level "function name(...) { ... }" in $content that
	 * isn't already guarded by an immediately-preceding
	 * "hm_exists('name')" check, in "if (!hm_exists('name')) { ... }" -
	 * matching the convention the file itself already uses elsewhere.
	 * Skips anonymous closures (no name) and anything nested inside a
	 * class body (class method redeclaration is a different failure mode
	 * this guard doesn't apply to, and none of the files this is used on
	 * currently define classes, but this stays defensive against that).
	 *
	 * Uses PHP's own tokenizer instead of brace-counting by hand so
	 * function boundaries are found correctly regardless of braces
	 * appearing inside strings/comments.
	 *
	 * @param string $content
	 * @return string Patched content (identical to input if nothing to do)
	 */
	private function wrapUnguardedTopLevelFunctions($content)
	{
		$tokens = token_get_all($content);
		$count = count($tokens);
		$result = '';
		$classDepth = null; // brace depth at which the current class body started, or null if not in one
		$braceDepth = 0;
		$i = 0;

		while ($i < $count) {
			$token = $tokens[$i];
			$text = is_array($token) ? $token[1] : $token;

			if ($text === '{') {
				$braceDepth++;
			} elseif ($text === '}') {
				$braceDepth--;
				if ($classDepth !== null && $braceDepth === $classDepth) {
					$classDepth = null; // left the class body
				}
			}

			if (is_array($token) && $token[0] === T_CLASS) {
				$classDepth = $braceDepth; // depth *before* the class's own "{" is seen
			}

			if (is_array($token) && $token[0] === T_FUNCTION && $classDepth === null) {
				$j = $i + 1;
				while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
					$j++;
				}
				$name = (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) ? $tokens[$j][1] : null;

				if ($name !== null) {
					// Find the end of this function (its own matching closing brace).
					$k = $j;
					$depth = 0;
					$started = false;
					while ($k < $count) {
						$t = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
						if ($t === '{') {
							$depth++;
							$started = true;
						} elseif ($t === '}') {
							$depth--;
						}
						$k++;
						if ($started && $depth === 0) {
							break;
						}
					}

					$funcSource = '';
					for ($m = $i; $m < $k; $m++) {
						$funcSource .= is_array($tokens[$m]) ? $tokens[$m][1] : $tokens[$m];
					}

					$alreadyGuarded = (strpos(substr($result, -200), "hm_exists('{$name}')") !== false);
					$result .= $alreadyGuarded
						? $funcSource
						: "if (!hm_exists('{$name}')) {\n" . $funcSource . "}\n";

					// Keep braceDepth consistent with however many '{'/'}' this
					// function's own source actually contained.
					$braceDepth += (substr_count($funcSource, '{') - substr_count($funcSource, '}'));
					$i = $k;
					continue;
				}
			}

			$result .= $text;
			$i++;
		}

		return $result;
	}
}
