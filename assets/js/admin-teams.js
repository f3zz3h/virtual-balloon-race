/* Virtual Balloon Race — team list editor (Settings and race screen). */
(function () {
	'use strict';

	function initEditor(root) {
		var list = root.querySelector('.vbr-teams-editor__list');
		var template = root.querySelector('.vbr-teams-editor__template');
		var palette = JSON.parse(root.getAttribute('data-palette') || '[]');
		var defaults = JSON.parse(root.getAttribute('data-defaults') || '[]');
		var next = list.children.length;

		function addRow(team) {
			var html = template.innerHTML.replace(/__i__/g, String(next++));
			var wrap = document.createElement('ul');
			wrap.innerHTML = html.trim();
			var row = wrap.firstElementChild;
			var count = list.children.length;

			row.querySelector('input[type="color"]').value = (team && team.color) || palette[count % palette.length] || '#2b6cb0';
			row.querySelector('input[type="text"]').value = (team && team.name) || '';
			list.appendChild(row);
			return row;
		}

		root.querySelector('.vbr-teams-editor__add').addEventListener('click', function () {
			addRow(null).querySelector('input[type="text"]').focus();
		});

		var defaultsBtn = root.querySelector('.vbr-teams-editor__defaults');
		if (defaultsBtn) {
			defaultsBtn.addEventListener('click', function () {
				var have = [].map.call(list.querySelectorAll('input[type="text"]'), function (input) {
					return input.value.trim().toLowerCase();
				});
				defaults.forEach(function (team) {
					if (have.indexOf(team.name.toLowerCase()) === -1) {
						addRow(team);
					}
				});
			});
		}

		list.addEventListener('click', function (e) {
			if (e.target.classList.contains('vbr-teams-editor__remove')) {
				e.target.closest('.vbr-teams-editor__row').remove();
			}
		});
	}

	document.querySelectorAll('.vbr-teams-editor').forEach(initEditor);
})();
