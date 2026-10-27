/* Virtual Balloon Race — prize rows on the race screen. */
(function () {
	'use strict';

	document.querySelectorAll('.lvbr-prizes-editor').forEach(function (root) {
		var list = root.querySelector('.lvbr-prizes-editor__list');
		var template = root.querySelector('.lvbr-prizes-editor__template');
		var next = 0;

		root.querySelector('.lvbr-prizes-editor__add').addEventListener('click', function () {
			var wrap = document.createElement('ol');
			wrap.innerHTML = template.innerHTML.replace(/__i__/g, 'n' + String(next++)).trim();
			var row = wrap.firstElementChild;
			// Suggest the next place: 1st, then 2nd, then 3rd…
			var kind = row.querySelector('.lvbr-prizes-editor__kind');
			var used = [].map.call(list.querySelectorAll('.lvbr-prizes-editor__kind'), function (s) { return s.value; });
			for (var place = 1; place <= 5; place++) {
				if (used.indexOf('place_' + place) === -1) {
					kind.value = 'place_' + place;
					break;
				}
			}
			list.appendChild(row);
			row.querySelector('.lvbr-prizes-editor__description input').focus();
		});

		list.addEventListener('change', function (e) {
			if (e.target.classList.contains('lvbr-prizes-editor__kind')) {
				e.target.closest('.lvbr-prizes-editor__row').querySelector('.lvbr-prizes-editor__label').hidden = e.target.value !== 'other';
			}
		});

		list.addEventListener('click', function (e) {
			if (e.target.classList.contains('lvbr-prizes-editor__remove')) {
				e.target.closest('.lvbr-prizes-editor__row').remove();
			}
		});
	});
})();
