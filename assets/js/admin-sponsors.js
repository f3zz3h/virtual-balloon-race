/* Virtual Balloon Race — sponsor cards on the race screen. */
(function () {
	'use strict';

	function initEditor(root) {
		var list = root.querySelector('.lvbr-sponsors-editor__list');
		var template = root.querySelector('.lvbr-sponsors-editor__template');
		var defaults = JSON.parse(root.getAttribute('data-tier-defaults') || '{}');
		var next = list.children.length;

		root.querySelector('.lvbr-sponsors-editor__add').addEventListener('click', function () {
			var wrap = document.createElement('ol');
			wrap.innerHTML = template.innerHTML.replace(/__i__/g, 'n' + String(next++)).trim();
			var card = wrap.firstElementChild;
			list.appendChild(card);
			card.querySelector('input[type="text"]').focus();
		});

		// The hot-air balloon's look only matters when it's ticked.
		function syncHotAir(card) {
			var box = card.querySelector('.lvbr-sponsors-editor__placements input[value="hot_air"]');
			card.querySelector('.lvbr-sponsors-editor__hot-air').hidden = !(box && box.checked);
		}

		// Choosing a tier ticks its usual placements.
		list.addEventListener('change', function (e) {
			var card = e.target.closest('.lvbr-sponsors-editor__card');
			if (!card) {
				return;
			}
			if (e.target.classList.contains('lvbr-sponsors-editor__tier')) {
				var picked = defaults[e.target.value] || [];
				card.querySelectorAll('.lvbr-sponsors-editor__placements input').forEach(function (box) {
					box.checked = picked.indexOf(box.value) !== -1;
				});
			}
			syncHotAir(card);
		});

		list.addEventListener('click', function (e) {
			var card = e.target.closest('.lvbr-sponsors-editor__card');
			if (!card) {
				return;
			}

			if (e.target.classList.contains('lvbr-sponsors-editor__remove')) {
				card.remove();
			} else if (e.target.classList.contains('lvbr-sponsors-editor__up') && card.previousElementSibling) {
				list.insertBefore(card, card.previousElementSibling);
				e.target.focus();
			} else if (e.target.classList.contains('lvbr-sponsors-editor__down') && card.nextElementSibling) {
				list.insertBefore(card.nextElementSibling, card);
				e.target.focus();
			} else if (e.target.classList.contains('lvbr-sponsors-editor__pick')) {
				pickLogo(card, e.target);
			}
		});

		function pickLogo(card, button) {
			if (!window.wp || !wp.media) {
				return;
			}
			var frame = wp.media({
				title: root.getAttribute('data-choose'),
				button: { text: root.getAttribute('data-use') },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var file = frame.state().get('selection').first().toJSON();
				var img = card.querySelector('.lvbr-sponsors-editor__logo-img');
				card.querySelector('.lvbr-sponsors-editor__logo-id').value = file.id;
				img.src = (file.sizes && file.sizes.thumbnail ? file.sizes.thumbnail.url : file.url);
				img.hidden = false;
				button.textContent = root.getAttribute('data-change');
			});
			frame.open();
		}
	}

	document.querySelectorAll('.lvbr-sponsors-editor').forEach(initEditor);
})();
