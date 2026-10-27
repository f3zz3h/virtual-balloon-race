/* Virtual Balloon Race — product page add-ons: team choice, launch picker, logo preview on a balloon. */
(function () {
	'use strict';

	var cfg = window.lvbrProduct || {};
	var i18n = cfg.i18n || {};
	var UNPICKED_HEX = '#b8c4d0';

	// The uploaded picture, shared by the big preview and the small one under the upload field.
	var look = { logo: null, fit: 'cover' };
	var redrawers = [];

	function redraw() {
		redrawers.forEach(function (fn) {
			fn();
		});
	}

	function field(name) {
		return document.querySelector('[name="' + name + '"]');
	}

	function chosenHex() {
		var select = field('lvbr_balloon_color');
		return (cfg.colors || {})[select ? select.value : ''] || '';
	}

	function chosenText(name) {
		var select = field(name);
		var option = select && select.value && select.options ? select.options[select.selectedIndex] : null;
		return option ? option.textContent.trim() : '';
	}

	function initLaunch(root) {
		var toggle = root.querySelector('input[name="lvbr_custom_launch"]');
		var panel = root.querySelector('.lvbr-addon__panel');
		var latInput = root.querySelector('input[name="lvbr_launch_lat"]');
		var lngInput = root.querySelector('input[name="lvbr_launch_lng"]');
		var labelInput = root.querySelector('input[name="lvbr_launch_label"]');
		var searchInput = root.querySelector('.lvbr-addon__search-input');
		var searchBtn = root.querySelector('.lvbr-addon__search-btn');
		var results = root.querySelector('.lvbr-addon__results');
		var chosen = root.querySelector('.lvbr-addon__chosen');
		var mapEl = root.querySelector('.lvbr-addon__map');
		var map = null;
		var marker = null;

		if (!toggle || !panel) {
			return;
		}

		function describe() {
			if (!latInput.value || !lngInput.value) {
				chosen.textContent = '';
				return;
			}
			var coords = Number(latInput.value).toFixed(4) + ', ' + Number(lngInput.value).toFixed(4);
			chosen.textContent = (i18n.chosen || 'Launching from:') + ' ' + (labelInput.value ? labelInput.value + ' (' + coords + ')' : coords);
		}

		function setPoint(lat, lng, label, pan) {
			latInput.value = Number(lat).toFixed(6);
			lngInput.value = Number(lng).toFixed(6);
			labelInput.value = label || '';
			if (map) {
				if (!marker) {
					marker = L.marker([lat, lng]).addTo(map);
				} else {
					marker.setLatLng([lat, lng]);
				}
				if (pan) {
					map.setView([lat, lng], 13);
				}
			}
			describe();
		}

		function ensureMap() {
			if (map || typeof L === 'undefined' || !mapEl) {
				if (map) {
					map.invalidateSize();
				}
				return;
			}

			var hasPoint = latInput.value && lngInput.value;
			var start = hasPoint ? [parseFloat(latInput.value), parseFloat(lngInput.value)] : (cfg.launch || [51.4885, -2.58]);

			map = L.map(mapEl).setView(start, hasPoint ? 12 : 9);
			window.lvbrServices.tileLayer().addTo(map);

			if (hasPoint) {
				marker = L.marker(start).addTo(map);
			}

			map.on('click', function (e) {
				setPoint(e.latlng.lat, e.latlng.lng, '', false);
			});
		}

		function showMessage(text) {
			results.innerHTML = '';
			var li = document.createElement('li');
			li.textContent = text;
			results.appendChild(li);
			results.hidden = false;
		}

		function search() {
			var q = searchInput.value.trim();
			if (!q) {
				return;
			}
			showMessage(i18n.searching || 'Searching…');

			window.lvbrServices.searchPlaces(q)
				.then(function (places) {
					results.innerHTML = '';
					if (!places.length) {
						showMessage(i18n.noResults || 'No places found.');
						return;
					}
					places.forEach(function (place) {
						var li = document.createElement('li');
						var btn = document.createElement('button');
						btn.type = 'button';
						btn.textContent = place.name;
						btn.addEventListener('click', function () {
							var short = place.name.split(',').slice(0, 2).join(',').trim();
							setPoint(place.lat, place.lng, short, true);
							results.hidden = true;
						});
						li.appendChild(btn);
						results.appendChild(li);
					});
					results.hidden = false;
				})
				.catch(function () {
					showMessage(i18n.error || 'Search failed.');
				});
		}

		toggle.addEventListener('change', function () {
			panel.hidden = !toggle.checked;
			if (toggle.checked) {
				ensureMap();
			}
		});

		if (searchBtn) {
			searchBtn.addEventListener('click', search);
		}

		if (searchInput) {
			// Enter in the search box should search, not submit the add-to-cart form.
			searchInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					search();
				}
			});
		}

		if (toggle.checked) {
			ensureMap();
		}
		describe();
	}

	function initLogo(input) {
		var preview = input.parentNode.querySelector('.lvbr-addon__preview');
		var chosen = input.parentNode.querySelector('input[name="lvbr_logo_chosen"]');
		var allowed = ['image/png', 'image/jpeg', 'image/webp'];
		var form = input.form;

		function hasFile() {
			return !!(input.files && input.files.length);
		}

		// Tells the server a picture should be arriving, so a lost file is reported, not silently dropped.
		function mark() {
			if (chosen) {
				chosen.value = hasFile() ? '1' : '';
			}
		}

		// Some themes add to the basket in the background (AJAX), which can't send files. With a
		// picture chosen, keep their handlers out of the way so the form posts normally.
		if (form) {
			form.setAttribute('method', 'post');
			form.setAttribute('enctype', 'multipart/form-data');

			window.addEventListener('click', function (e) {
				var button = e.target && e.target.closest ? e.target.closest('button, input[type="submit"]') : null;
				if (hasFile() && button && button.form === form && button.type === 'submit') {
					e.stopImmediatePropagation();
				}
			}, true);
			window.addEventListener('submit', function (e) {
				if (hasFile() && e.target === form) {
					e.stopImmediatePropagation();
				}
			}, true);
		}
		mark();

		// Without the big preview, draw the picture on a small balloon under the upload field.
		if (preview && !document.querySelector('[data-lvbr-preview]')) {
			redrawers.push(function () {
				if (!look.logo || !window.lvbrBalloon) {
					preview.hidden = true;
					return;
				}
				preview.innerHTML = window.lvbrBalloon.svg(chosenHex() || '#2b6cb0', false, look.logo, look.fit);
				preview.hidden = false;
			});
		}

		input.addEventListener('change', function () {
			var file = input.files && input.files[0];

			mark();
			input.setCustomValidity('');
			if (look.logo && window.URL && URL.revokeObjectURL) {
				URL.revokeObjectURL(look.logo);
			}
			look.logo = null;
			redraw();

			if (!file) {
				return;
			}

			if (allowed.indexOf(file.type) === -1) {
				input.setCustomValidity(i18n.badType || 'Please choose a PNG, JPG or WebP image.');
			} else if (cfg.maxLogo && file.size > cfg.maxLogo) {
				input.setCustomValidity(i18n.tooBig || 'That image is too large.');
			}

			if (input.validationMessage) {
				input.reportValidity();
				return;
			}

			if (window.URL && URL.createObjectURL) {
				var url = URL.createObjectURL(file);
				var img = new Image();
				// Same rule as the map: very wide or tall pictures are shown whole, others fill the balloon.
				img.onload = function () {
					var ratio = img.naturalHeight ? img.naturalWidth / img.naturalHeight : 1;
					look.fit = ratio > 1.6 || ratio < 0.6 ? 'contain' : 'cover';
					look.logo = url;
					redraw();
				};
				img.src = url;
			}
		});
	}

	// Big balloon in place of the product images: colour, picture, name, team and helium as chosen.
	function initPreview(root) {
		var balloon = root.querySelector('.lvbr-product-preview__balloon');
		var nameEl = root.querySelector('.lvbr-product-preview__name');
		var metaEl = root.querySelector('.lvbr-product-preview__meta');

		redrawers.push(function () {
			var hex = chosenHex();
			var nameInput = field('lvbr_balloon_name');
			var name = nameInput ? nameInput.value.trim() : '';
			var helium = field('lvbr_helium_level');
			// Helium option labels include prices and notes; the value is the short strategy name.
			var meta = [chosenText('lvbr_balloon_color'), chosenText('lvbr_team_id'), helium ? helium.value : ''].filter(Boolean);

			if (window.lvbrBalloon) {
				balloon.innerHTML = window.lvbrBalloon.svg(hex || UNPICKED_HEX, false, look.logo, look.fit);
			}
			root.classList.toggle('is-unpicked', !hex && !look.logo);
			root.setAttribute('data-helium', helium ? helium.value : '');
			nameEl.textContent = name || i18n.yourBalloon || 'Your balloon';
			metaEl.textContent = hex ? meta.join(' · ') : (i18n.pickColour || 'Pick a colour to see your balloon.');
		});

		['lvbr_balloon_color', 'lvbr_team_id', 'lvbr_helium_level', 'lvbr_race_id'].forEach(function (name) {
			var el = field(name);
			if (el) {
				el.addEventListener('change', redraw);
			}
		});

		var nameInput = field('lvbr_balloon_name');
		if (nameInput) {
			nameInput.addEventListener('input', redraw);
		}
	}

	// With several races open, only offer the chosen race's teams; hide the field for races without teams.
	function initTeams(select) {
		var raceSelect = document.querySelector('select[name="lvbr_race_id"]');
		var row = select.closest('.lvbr-team-field') || select.parentNode;

		if (!raceSelect) {
			return;
		}

		function filter() {
			var race = raceSelect.value;
			var any = false;

			[].forEach.call(select.options, function (option) {
				if (!option.value) {
					return;
				}
				var match = option.getAttribute('data-race') === race;
				option.hidden = !match;
				option.disabled = !match;
				any = any || match;
				if (!match && option.selected) {
					select.value = '';
				}
			});

			row.hidden = !any;
			select.required = any;
		}

		raceSelect.addEventListener('change', filter);
		filter();
	}

	document.querySelectorAll('[data-lvbr-preview]').forEach(initPreview);
	document.querySelectorAll('select[name="lvbr_team_id"]').forEach(initTeams);
	document.querySelectorAll('[data-lvbr-launch]').forEach(initLaunch);
	document.querySelectorAll('input[name="lvbr_logo"]').forEach(initLogo);

	// Keep the colour list in step with the preview too (the small preview only needs this).
	var colorSelect = field('lvbr_balloon_color');
	if (colorSelect && !document.querySelector('[data-lvbr-preview]')) {
		colorSelect.addEventListener('change', redraw);
	}
	redraw();
})();
