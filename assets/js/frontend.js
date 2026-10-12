(function () {
	'use strict';

	function bindLeaderboard(root) {
		var input = root.querySelector('.vbr-leaderboard__search-input');
		var rows = root.querySelectorAll('tbody tr[data-vbr-search]');
		var empty = root.querySelector('.vbr-leaderboard__empty-search');

		if (!input) {
			return;
		}

		input.addEventListener('input', function () {
			var query = input.value.toLowerCase().trim();
			var visible = 0;

			rows.forEach(function (row) {
				var haystack = row.getAttribute('data-vbr-search') || '';
				var match = !query || haystack.indexOf(query) !== -1;
				row.hidden = !match;
				if (match) {
					visible += 1;
				}
			});

			if (empty) {
				empty.hidden = visible !== 0 || rows.length === 0;
			}
		});
	}

	document.querySelectorAll('[data-vbr-leaderboard]').forEach(bindLeaderboard);
})();
