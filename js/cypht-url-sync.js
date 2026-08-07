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
 * Keeps the browser URL in step with the Cypht page shown in the iframe, so
 * reloading returns to the same place and the address bar is meaningful.
 *
 * The whole Cypht query string is nested in one "cypht" parameter rather than
 * mirrored as separate ones: Cypht uses page/id/uid/list_path and Dolibarr
 * uses action/id/token, and merging the two namespaces collides on "id".
 * cyphtWebmailindex.php reads it back and builds the iframe src from it.
 */
(function () {
	'use strict';

	var FRAME_ID = 'cyphtwebmail-frame';
	var PARAM = 'cypht';

	/* Cypht is a single page app: its router calls history.pushState (see
	 * modules/core/navigation/navigation.js), which changes the frame's URL
	 * without any navigation. No event reaches a parent document for that -
	 * popstate covers back/forward only - so the location is polled instead.
	 * Same origin, so it is readable directly with no postMessage handshake. */
	var POLL_MS = 400;

	function currentFrameQuery(frame) {
		try {
			return frame.contentWindow.location.search.replace(/^\?/, '');
		} catch (e) {
			// Cypht reconfigured onto another origin: leave the URL alone
			// rather than throwing on every tick.
			return null;
		}
	}

	function writeParentUrl(query) {
		var url = new URL(window.location.href);

		if (query) {
			url.searchParams.set(PARAM, query);
		} else {
			url.searchParams.delete(PARAM);
		}

		window.history.replaceState(null, '', url.toString());
	}

	function start() {
		var frame = document.getElementById(FRAME_ID);
		if (!frame || !window.history || !window.history.replaceState) {
			return;
		}

		var last = null;

		function sync() {
			var query = currentFrameQuery(frame);
			if (query === null || query === last) {
				return;
			}
			last = query;
			writeParentUrl(query);
		}

		frame.addEventListener('load', sync);
		window.setInterval(sync, POLL_MS);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
