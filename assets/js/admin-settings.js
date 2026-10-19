/* Virtual Balloon Race — settings page: launch point map + place search. */
(function () {
	'use strict';

	var mapEl = document.getElementById('vbr-launch-map');
	var latInput = document.getElementById('vbr_launch_lat');
	var lngInput = document.getElementById('vbr_launch_lng');
	var labelInput = document.getElementById('vbr_launch_label');
	var searchInput = document.getElementById('vbr-place-search');
	var searchBtn = document.getElementById('vbr-place-search-btn');
	var results = document.getElementById('vbr-place-results');
	var i18n = (window.vbrSettings && window.vbrSettings.i18n) || {};

	if (!mapEl || !latInput || !lngInput || typeof L === 'undefined') {
		return;
	}

	var start = [parseFloat(latInput.value) || 51.4885, parseFloat(lngInput.value) || -2.58];
	var map = L.map(mapEl).setView(start, parseInt(mapEl.getAttribute('data-zoom'), 10) || 7);

	L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
		maxZoom: 19,
		attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
	}).addTo(map);

	var marker = L.marker(start, { draggable: true }).addTo(map);

	function setPoint(lat, lng, pan) {
		latInput.value = Number(lat).toFixed(7);
		lngInput.value = Number(lng).toFixed(7);
		marker.setLatLng([lat, lng]);
		if (pan) {
			map.setView([lat, lng], Math.max(map.getZoom(), 10));
		}
	}

	map.on('click', function (e) {
		setPoint(e.latlng.lat, e.latlng.lng, false);
	});

	marker.on('dragend', function () {
		var p = marker.getLatLng();
		setPoint(p.lat, p.lng, false);
	});

	function onInputChange() {
		var lat = parseFloat(latInput.value);
		var lng = parseFloat(lngInput.value);
		if (!isNaN(lat) && !isNaN(lng)) {
			marker.setLatLng([lat, lng]);
			map.panTo([lat, lng]);
		}
	}

	latInput.addEventListener('change', onInputChange);
	lngInput.addEventListener('change', onInputChange);

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
					btn.className = 'button-link';
					btn.textContent = place.display_name;
					btn.addEventListener('click', function () {
						setPoint(parseFloat(place.lat), parseFloat(place.lon), true);
						if (labelInput) {
							labelInput.value = place.display_name.split(',').slice(0, 2).join(',').trim();
						}
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

	searchBtn.addEventListener('click', search);
	searchInput.addEventListener('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			search();
		}
	});
})();
