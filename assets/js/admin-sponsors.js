/* Virtual Balloon Race — sponsor cards on the race screen. */
(function () {
	'use strict';

	function initEditor(root) {
		var list = root.querySelector('.vbr-sponsors-editor__list');
		var template = root.querySelector('.vbr-sponsors-editor__template');
		var defaults = JSON.parse(root.getAttribute('data-tier-defaults') || '{}');
		var next = list.children.length;

		root.querySelector('.vbr-sponsors-editor__add').addEventListener('click', function () {
			var wrap = document.createElement('ol');
			wrap.innerHTML = template.innerHTML.replace(/__i__/g, 'n' + String(next++)).trim();
			var card = wrap.firstElementChild;
			list.appendChild(card);
			card.querySelector('input[type="text"]').focus();
		});

		// Choosing a tier ticks its usual placements.
		list.addEventListener('change', function (e) {
			if (!e.target.classList.contains('vbr-sponsors-editor__tier')) {
				return;
			}
			var picked = defaults[e.target.value] || [];
			e.target.closest('.vbr-sponsors-editor__card').querySelectorAll('.vbr-sponsors-editor__placements input').forEach(function (box) {
				box.checked = picked.indexOf(box.value) !== -1;
			});
		});

		list.addEventListener('click', function (e) {
			var card = e.target.closest('.vbr-sponsors-editor__card');
			if (!card) {
				return;
			}

			if (e.target.classList.contains('vbr-sponsors-editor__remove')) {
				card.remove();
			} else if (e.target.classList.contains('vbr-sponsors-editor__up') && card.previousElementSibling) {
				list.insertBefore(card, card.previousElementSibling);
				e.target.focus();
			} else if (e.target.classList.contains('vbr-sponsors-editor__down') && card.nextElementSibling) {
				list.insertBefore(card.nextElementSibling, card);
				e.target.focus();
			} else if (e.target.classList.contains('vbr-sponsors-editor__pick')) {
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
				var img = card.querySelector('.vbr-sponsors-editor__logo-img');
				card.querySelector('.vbr-sponsors-editor__logo-id').value = file.id;
				img.src = (file.sizes && file.sizes.thumbnail ? file.sizes.thumbnail.url : file.url);
				img.hidden = false;
				button.textContent = root.getAttribute('data-change');
			});
			frame.open();
		}
	}

	document.querySelectorAll('.vbr-sponsors-editor').forEach(initEditor);
})();
