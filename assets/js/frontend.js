(function () {
	'use strict';

	function bindLeaderboard(root) {
		var input = root.querySelector('.vbr-leaderboard__search-input');
		var rows = root.querySelectorAll('tbody tr[data-vbr-search]');
		var empty = root.querySelector('.vbr-leaderboard__empty-search');
		var more = root.querySelector('.vbr-leaderboard__more');
		var toggles = root.querySelectorAll('[data-vbr-show]');
		var onlyMine = false;

		// Search looks at every balloon; with no search, rows past the limit stay hidden.
		function apply() {
			var query = input ? input.value.toLowerCase().trim() : '';
			var visible = 0;

			rows.forEach(function (row) {
				var haystack = row.getAttribute('data-vbr-search') || '';
				var match = query ? haystack.indexOf(query) !== -1 : (onlyMine || !row.classList.contains('is-extra'));

				if (onlyMine && !row.hasAttribute('data-vbr-mine')) {
					match = false;
				}

				row.hidden = !match;
				if (match) {
					visible += 1;
				}
			});

			if (empty) {
				empty.hidden = visible !== 0 || rows.length === 0;
			}
			if (more) {
				more.hidden = !!query || onlyMine;
			}
		}

		if (input) {
			input.addEventListener('input', apply);
		}

		toggles.forEach(function (btn) {
			btn.addEventListener('click', function () {
				onlyMine = btn.getAttribute('data-vbr-show') === 'mine';
				toggles.forEach(function (other) {
					other.setAttribute('aria-pressed', String(other === btn));
				});
				apply();
			});
		});
	}

	document.querySelectorAll('[data-vbr-leaderboard]').forEach(bindLeaderboard);
})();
