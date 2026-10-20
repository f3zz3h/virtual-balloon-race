/* Virtual Balloon Race — product page add-ons: launch picker + logo preview on a balloon. */
(function () {
	'use strict';

	var cfg = window.vbrProduct || {};
	var i18n = cfg.i18n || {};

	function initLaunch(root) {
		var toggle = root.querySelector('input[name="vbr_custom_launch"]');
		var panel = root.querySelector('.vbr-addon__panel');
		var latInput = root.querySelector('input[name="vbr_launch_lat"]');
		var lngInput = root.querySelector('input[name="vbr_launch_lng"]');
		var labelInput = root.querySelector('input[name="vbr_launch_label"]');
		var searchInput = root.querySelector('.vbr-addon__search-input');
		var searchBtn = root.querySelector('.vbr-addon__search-btn');
		var results = root.querySelector('.vbr-addon__results');
		var chosen = root.querySelector('.vbr-addon__chosen');
		var mapEl = root.querySelector('.vbr-addon__map');
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
			L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
				maxZoom: 19,
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
			}).addTo(map);

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

			fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&q=' + encodeURIComponent(q), {
				headers: { 'Accept': 'application/json' }
			})
				.then(function (r) { return r.json(); })
				.then(function (places) {
					results.innerHTML = '';
					if (!places || !places.length) {
						showMessage(i18n.noResults || 'No places found.');
						return;
					}
					places.forEach(function (place) {
						var li = document.createElement('li');
						var btn = document.createElement('button');
						btn.type = 'button';
						btn.textContent = place.display_name;
						btn.addEventListener('click', function () {
							var short = place.display_name.split(',').slice(0, 2).join(',').trim();
							setPoint(parseFloat(place.lat), parseFloat(place.lon), short, true);
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
		var preview = input.parentNode.querySelector('.vbr-addon__preview');
		var colorSelect = document.querySelector('select[name="vbr_balloon_color"]');
		var allowed = ['image/png', 'image/jpeg', 'image/webp'];
		var logo = null;
		var fit = 'cover';

		// Draw the picture on a balloon in the chosen colour, as it will look on the map.
		function render() {
			if (!preview || !logo || !window.vbrBalloon) {
				return;
			}
			var hex = (cfg.colors || {})[colorSelect ? colorSelect.value : ''] || '#2b6cb0';
			preview.innerHTML = window.vbrBalloon.svg(hex, false, logo, fit);
			preview.hidden = false;
		}

		if (colorSelect) {
			colorSelect.addEventListener('change', render);
		}

		input.addEventListener('change', function () {
			var file = input.files && input.files[0];

			input.setCustomValidity('');
			logo = null;
			if (preview) {
				preview.hidden = true;
			}

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

			if (preview && window.URL && URL.createObjectURL) {
				var url = URL.createObjectURL(file);
				var img = new Image();
				// Same rule as the map: very wide or tall pictures are shown whole, others fill the balloon.
				img.onload = function () {
					var ratio = img.naturalHeight ? img.naturalWidth / img.naturalHeight : 1;
					fit = ratio > 1.6 || ratio < 0.6 ? 'contain' : 'cover';
					logo = url;
					render();
				};
				img.src = url;
			}
		});
	}

	document.querySelectorAll('[data-vbr-launch]').forEach(initLaunch);
	document.querySelectorAll('input[name="vbr_logo"]').forEach(initLogo);
})();
