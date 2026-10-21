(function () {
	'use strict';

	var i18n = (window.vbrLeaderboard && window.vbrLeaderboard.i18n) || {};
	var STORE_KEY = 'vbrLeaderboardPerPage';

	// "%1$s–%2$s of %3$s" style placeholders, or a single "%s".
	function format(text) {
		var args = arguments;
		return String(text)
			.replace(/%(\d)\$s/g, function (m, n) { return args[n]; })
			.replace('%s', args[1]);
	}

	function number(value) {
		return Number(value).toLocaleString();
	}

	// A visitor's entries-per-page choice, remembered on their device (storage can be unavailable).
	function storedPerPage() {
		try {
			var value = window.localStorage.getItem(STORE_KEY);
			return value === null ? null : parseInt(value, 10);
		} catch (e) {
			return null;
		}
	}

	function storePerPage(value) {
		try {
			window.localStorage.setItem(STORE_KEY, String(value));
		} catch (e) { /* Not remembered; that's fine. */ }
	}

	function bindLeaderboard(root) {
		var input = root.querySelector('.vbr-leaderboard__search-input');
		var rows = [].slice.call(root.querySelectorAll('tbody tr[data-vbr-search]'));
		var empty = root.querySelector('.vbr-leaderboard__empty-search');
		var toggles = root.querySelectorAll('[data-vbr-show]');
		var pager = root.querySelector('[data-vbr-pager]');
		var select = pager ? pager.querySelector('.vbr-pager__select') : null;
		var summary = pager ? pager.querySelector('.vbr-pager__summary') : null;
		var nav = pager ? pager.querySelector('.vbr-pager__pages') : null;
		var onlyMine = false;
		var page = 1;
		var perPage = parseInt(root.getAttribute('data-vbr-per-page'), 10);
		var stored = storedPerPage();

		if (isNaN(perPage)) {
			perPage = 10;
		}

		if (select && stored !== null && !isNaN(stored) && select.querySelector('option[value="' + stored + '"]')) {
			perPage = stored;
			select.value = String(stored);
		}

		function matches(row) {
			var query = input ? input.value.toLowerCase().trim() : '';

			if (onlyMine && !row.hasAttribute('data-vbr-mine')) {
				return false;
			}
			return !query || (row.getAttribute('data-vbr-search') || '').indexOf(query) !== -1;
		}

		function pageButton(label, target, current, disabled) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'vbr-pager__page';
			btn.textContent = label;
			btn.disabled = !!disabled;
			if (current) {
				btn.setAttribute('aria-current', 'page');
			}
			if (/^\d+$/.test(label)) {
				btn.setAttribute('aria-label', format(i18n.page || 'Page %s', label));
			}
			btn.addEventListener('click', function () {
				page = target;
				apply(true);
			});
			return btn;
		}

		// First, last, and the pages either side of the current one, with gaps as "…".
		function renderPages(pages) {
			nav.innerHTML = '';
			nav.hidden = pages <= 1;

			if (pages <= 1) {
				return;
			}

			nav.appendChild(pageButton('‹ ' + (i18n.previous || 'Previous'), page - 1, false, page === 1));

			var last = 0;
			for (var p = 1; p <= pages; p++) {
				if (p === 1 || p === pages || Math.abs(p - page) <= 1) {
					if (last && p - last > 1) {
						var gap = document.createElement('span');
						gap.className = 'vbr-pager__gap';
						gap.textContent = '…';
						nav.appendChild(gap);
					}
					nav.appendChild(pageButton(String(p), p, p === page, false));
					last = p;
				}
			}

			nav.appendChild(pageButton((i18n.next || 'Next') + ' ›', page + 1, false, page === pages));
		}

		// Filter (search, My balloons), then show one page of the matches.
		function apply(fromPager) {
			var list = rows.filter(matches);
			var pages = perPage ? Math.max(1, Math.ceil(list.length / perPage)) : 1;

			page = Math.min(Math.max(1, page), pages);

			var start = perPage ? (page - 1) * perPage : 0;
			var end = perPage ? start + perPage : list.length;

			rows.forEach(function (row) {
				row.hidden = true;
			});
			list.forEach(function (row, i) {
				row.hidden = i < start || i >= end;
			});

			if (empty) {
				empty.hidden = list.length !== 0 || rows.length === 0;
			}

			if (summary) {
				summary.textContent = list.length
					? format(i18n.showing || 'Showing %1$s–%2$s of %3$s', number(start + 1), number(Math.min(end, list.length)), number(list.length))
					: (i18n.none || 'No balloons to show');
			}

			if (nav) {
				renderPages(pages);
			}

			// After changing page from the controls under the table, bring the table's top back into view.
			if (fromPager && root.getBoundingClientRect().top < 0 && root.scrollIntoView) {
				root.scrollIntoView({ block: 'start' });
			}
		}

		if (input) {
			input.addEventListener('input', function () {
				page = 1;
				apply(false);
			});
		}

		if (select) {
			select.addEventListener('change', function () {
				perPage = parseInt(select.value, 10) || 0;
				storePerPage(perPage);
				page = 1;
				apply(false);
			});
		}

		toggles.forEach(function (btn) {
			btn.addEventListener('click', function () {
				onlyMine = btn.getAttribute('data-vbr-show') === 'mine';
				toggles.forEach(function (other) {
					other.setAttribute('aria-pressed', String(other === btn));
				});
				page = 1;
				apply(false);
			});
		});

		apply(false);
	}

	document.querySelectorAll('[data-vbr-leaderboard]').forEach(bindLeaderboard);
})();
