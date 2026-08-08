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

require_once __DIR__ . '/../install/paths.class.php';

/**
 * \file        class/install/upstreampatches.class.php
 * \ingroup     cyphtWebmail
 * \brief       Patches an upstream Cypht bug that surfaces once a module's
 *              functions.php gets require()'d twice in the same process,
 *              which performSsoLogin()'s "functional login" call does.
 */
class CyphtUpstreamPatches
{
	/**
	 * @var string  Last error message, if any call returned false/failure.
	 */
	public $error = '';

	/**
	 * @var CyphtPaths
	 */
	private $paths;

	/**
	 * @param CyphtPaths $paths
	 */
	public function __construct(CyphtPaths $paths)
	{
		$this->paths = $paths;
	}

	/**
	 * Most functions in modules/core/functions.php guard against being
	 * require()'d twice with "if (!hm_exists('name')) { ... }", but a few
	 * are missing that guard (as of Cypht 2.11.1). Harmless normally, but
	 * performSsoLogin()'s "functional login" call does load modules twice
	 * in one process, causing a fatal "Cannot redeclare ...()".
	 *
	 * Rather than hardcode that list, which could shift on a future Cypht
	 * release, this scans the file with PHP's tokenizer and wraps every
	 * unguarded top-level function, skipping anything already wrapped.
	 *
	 * Applied by the build process, not hand-edited in vendor/, so
	 * "composer install" re-fetching this package never reverts it.
	 *
	 * @return bool
	 */
	public function patchCoreFunctionsGuard()
	{
		$path = $this->paths->getCyphtPath() . '/modules/core/functions.php';
		if (!is_readable($path)) {
			return true; // nothing to patch yet
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
	 * Wraps every unguarded top-level "function name(...) { ... }" in
	 * "if (!hm_exists('name')) { ... }". Skips anonymous closures and
	 * anything nested inside a class body. Uses PHP's tokenizer instead
	 * of brace-counting so boundaries are found correctly even with
	 * braces inside strings/comments.
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
