/* Virtual Balloon Race — settings page: launch point map + place search. */
(function () {
	'use strict';

	var mapEl = document.getElementById('lvbr-launch-map');
	var latInput = document.getElementById('lvbr_launch_lat');
	var lngInput = document.getElementById('lvbr_launch_lng');
	var labelInput = document.getElementById('lvbr_launch_label');
	var searchInput = document.getElementById('lvbr-place-search');
	var searchBtn = document.getElementById('lvbr-place-search-btn');
	var results = document.getElementById('lvbr-place-results');
	var i18n = (window.lvbrSettings && window.lvbrSettings.i18n) || {};

	if (!mapEl || !latInput || !lngInput || typeof L === 'undefined') {
		return;
	}

	var start = [parseFloat(latInput.value) || 51.4447, parseFloat(lngInput.value) || -2.638];
	var map = L.map(mapEl).setView(start, parseInt(mapEl.getAttribute('data-zoom'), 10) || 7);

	window.lvbrServices.tileLayer().addTo(map);

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
					btn.className = 'button-link';
					btn.textContent = place.name;
					btn.addEventListener('click', function () {
						setPoint(place.lat, place.lng, true);
						if (labelInput) {
							labelInput.value = place.name.split(',').slice(0, 2).join(',').trim();
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

	if (!searchBtn || !searchInput) {
		return;
	}

	searchBtn.addEventListener('click', search);
	searchInput.addEventListener('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			search();
		}
	});
})();
