(function () {
	'use strict';

	function run() {
		var failed = false;

		// Check regular stylesheets: sheet === null means a network error or non-2xx.
		// Cross-origin sheets throw SecurityError on .sheet access — they loaded OK.
		var links = document.querySelectorAll('link[rel="stylesheet"]');
		for (var i = 0; i < links.length; i++) {
			try {
				if (links[i].sheet === null) {
					failed = true;
					break;
				}
			} catch (e) {
				// Cross-origin, loaded OK.
			}
		}

		// Check preloaded stylesheets (Divi Dynamic CSS late-loading pattern).
		// Divi uses: <link rel="preload" as="style" onload="this.rel='stylesheet'">
		// If the resource loaded successfully, onload fires and rel becomes "stylesheet".
		// If it failed (404), onload never fires and rel stays "preload" after window.load.
		if (!failed) {
			var preloads = document.querySelectorAll('link[as="style"]');
			for (var j = 0; j < preloads.length; j++) {
				if (preloads[j].rel === 'preload') {
					failed = true;
					break;
				}
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
