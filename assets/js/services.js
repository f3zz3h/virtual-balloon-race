/* Virtual Balloon Race — map tiles and place search, as chosen in Settings → Maps, weather and place search. */
(function () {
	'use strict';

	var cfg = window.lvbrServicesConfig || {};
	var tiles = cfg.tiles || { url: '', options: {} };
	var search = cfg.search || { provider: 'nominatim' };
	var nextSearchAt = 0;

	function json(response) {
		if (!response.ok) {
			throw new Error('HTTP ' + response.status);
		}
		return response.json();
	}

	window.lvbrServices = {
		/* A Leaflet tile layer for the chosen provider (Leaflet must be loaded by then). */
		tileLayer: function () {
			// No tiles until the site owner allows outside services: balloons on a plain background.
			return tiles.url ? L.tileLayer(tiles.url, tiles.options) : L.layerGroup();
		},

		/*
		 * Search for a place. Resolves to [{name, lat, lng}].
		 * At most one request a second, as Nominatim's usage policy asks; call it on a button press, not while typing.
		 */
		searchPlaces: function (query) {
			var wait = Math.max(0, nextSearchAt - Date.now());
			nextSearchAt = Date.now() + wait + 1000;

			return new Promise(function (resolve) {
				setTimeout(resolve, wait);
			}).then(function () {
				if (search.provider === 'off') {
					return [];
				}

				if (search.provider === 'maptiler') {
					return fetch('https://api.maptiler.com/geocoding/' + encodeURIComponent(query) + '.json?limit=5&key=' + encodeURIComponent(search.key))
						.then(json)
						.then(function (data) {
							return (data.features || []).map(function (f) {
								return { name: f.place_name, lat: f.center[1], lng: f.center[0] };
							});
						});
				}

				return fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&q=' + encodeURIComponent(query), {
					headers: { 'Accept': 'application/json' }
				})
					.then(json)
					.then(function (places) {
						return (places || []).map(function (p) {
							return { name: p.display_name, lat: parseFloat(p.lat), lng: parseFloat(p.lon) };
						});
					});
			});
		}
	};
})();
