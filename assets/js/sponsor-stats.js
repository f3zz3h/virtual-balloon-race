/* Virtual Balloon Race — counts how often sponsor placements are shown and clicked (totals only: no cookies or visitor data). */
(function () {
	'use strict';

	var cfg = window.lvbrSponsorStats || {};
	var seen = {};
	var queue = [];
	var timer = null;

	if (!cfg.url) {
		return;
	}

	function send(type, items) {
		var body = JSON.stringify({ type: type, items: items });
		if (navigator.sendBeacon && navigator.sendBeacon(cfg.url, new Blob([body], { type: 'application/json' }))) {
			return;
		}
		if (window.fetch) {
			fetch(cfg.url, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true, credentials: 'omit' }).catch(function () {});
		}
	}

	// Views: each sponsor placement once per page, sent together shortly after it's drawn.
	function view(id, placement) {
		var key = id + ':' + placement;
		if (!id || seen[key]) {
			return;
		}
		seen[key] = true;
		queue.push(key);
		clearTimeout(timer);
		timer = setTimeout(function () {
			if (queue.length) {
				send('view', queue.splice(0, 50));
			}
		}, 1500);
	}

	function collect(root) {
		(root || document).querySelectorAll('[data-lvbr-sponsor]').forEach(function (el) {
			view(el.getAttribute('data-lvbr-sponsor'), el.getAttribute('data-lvbr-placement'));
		});
	}

	// Clicks on any sponsor link, including ones in map popups.
	document.addEventListener('click', function (e) {
		var link = e.target.closest ? e.target.closest('a[data-lvbr-sponsor]') : null;
		if (link) {
			send('click', [link.getAttribute('data-lvbr-sponsor') + ':' + link.getAttribute('data-lvbr-placement')]);
		}
	}, true);

	cfg.view = view;
	cfg.collect = collect;

	(window.lvbrSponsorStatsQueue || []).forEach(function (pair) {
		view(pair[0], pair[1]);
	});
	window.lvbrSponsorStatsQueue = [];

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { collect(); });
	} else {
		collect();
	}
})();
