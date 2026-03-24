(function () {
	'use strict';

	function run() {
		var links = document.querySelectorAll('link[rel="stylesheet"]');
		var failed = false;

		for (var i = 0; i < links.length; i++) {
			try {
				// sheet === null means the browser attempted to load the stylesheet
				// but received a network error or non-2xx response.
				// Cross-origin stylesheets throw a SecurityError on .sheet access —
				// that means they loaded (just restricted), so we catch and skip.
				if (links[i].sheet === null) {
					failed = true;
					break;
				}
			} catch (e) {
				// Cross-origin, loaded OK.
			}
		}

		if (!failed) return;

		// Guard against reload loops: only attempt one heal per path per session.
		var guardKey = 'pbcw_healed';
		if (sessionStorage.getItem(guardKey) === location.pathname) return;
		sessionStorage.setItem(guardKey, location.pathname);

		// Notify the server. It will warm origin + CF edge for this URL,
		// then we reload so the browser gets everything fresh from CF cache.
		fetch(window.pbcw_heal.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.pbcw_heal.nonce
			},
			body: JSON.stringify({ url: location.href })
		}).then(function () {
			// 3s gives the server time to complete the warmup before we reload.
			setTimeout(function () { location.reload(true); }, 3000);
		}).catch(function () {
			// Heal request failed — reload anyway; may recover on its own.
			setTimeout(function () { location.reload(true); }, 3000);
		});
	}

	// Run after all resources have had a chance to load.
	if (document.readyState === 'complete') {
		run();
	} else {
		window.addEventListener('load', run);
	}
}());
