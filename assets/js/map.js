/* Virtual Balloon Race — live Leaflet map with trails. */
(function () {
	'use strict';

	if (typeof L === 'undefined') {
		return;
	}

	var BURST_HEX = '#8a8f98';

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	function safeHex(hex) {
		return /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(hex || '') ? hex : '#555555';
	}

	// Lighten (amount > 0) or darken (amount < 0) a hex colour.
	function shade(hex, amount) {
		var h = safeHex(hex).slice(1);
		if (h.length === 3) {
			h = h.replace(/./g, '$&$&');
		}
		var n = parseInt(h, 16);
		return 'rgb(' + [n >> 16, (n >> 8) & 255, n & 255].map(function (c) {
			return Math.round(amount < 0 ? c * (1 + amount) : c + (255 - c) * amount);
		}).join(',') + ')';
	}

	// A balloon drawn in a 24×36 box. The string ends at the bottom centre, which sits on the balloon's position.
	function balloonSvg(hex, burst) {
		var string = '<path d="M12 26.8c-1.8 2.2 1.8 4.4 0 6.6-.6.8-.6 1.6-.2 2.4" fill="none" stroke="#5b6b78" stroke-width="0.9" stroke-linecap="round"/>';

		if (burst) {
			return '<svg class="vbr-balloon__svg" viewBox="0 0 24 36" aria-hidden="true">' +
				'<path d="M12 22.5c-1.8 2.6 1.8 5.2 0 7.8-.6 1-.6 2.6-.2 5" fill="none" stroke="#5b6b78" stroke-width="0.9" stroke-linecap="round"/>' +
				'<path d="M12 4.5l1.7 4.6 4.5-2.1-1.9 4.6 4.8 1.3-4.4 2.3 2.8 4.1-4.8-.9.1 4.9L12 19.6l-2.8 3.7.1-4.9-4.8.9 2.8-4.1-4.4-2.3 4.8-1.3L5.8 7l4.5 2.1z" fill="' + BURST_HEX + '" stroke="#6b7079" stroke-width="0.8" stroke-linejoin="round"/>' +
				'</svg>';
		}

		var fill = safeHex(hex);
		var edge = shade(fill, -0.35);

		return '<svg class="vbr-balloon__svg" viewBox="0 0 24 36" aria-hidden="true">' +
			string +
			'<path d="M12 1C5.9 1 2 5.9 2 11.6 2 18.2 7.3 23.6 12 25c4.7-1.4 10-6.8 10-13.4C22 5.9 18.1 1 12 1z" fill="' + fill + '" stroke="' + edge + '" stroke-width="1"/>' +
			'<path d="M10.4 26.8 12 24.6l1.6 2.2z" fill="' + edge + '" stroke="' + edge + '" stroke-width="0.6" stroke-linejoin="round"/>' +
			'<ellipse cx="7.6" cy="8" rx="2.1" ry="3.6" transform="rotate(25 7.6 8)" fill="#fff" fill-opacity=".45"/>' +
			'</svg>';
	}

	// Small balloon for the side list and popups.
	function miniBalloon(b) {
		var span = el('span', 'vbr-mini-balloon');
		span.innerHTML = balloonSvg(b.hex, b.burst);
		return span;
	}

	function formatKm(value) {
		return Number(value).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
	}

	function RaceMap(root) {
		var configEl = root.querySelector('.vbr-map__config');
		this.root = root;
		this.config = JSON.parse(configEl ? configEl.textContent : '{}');
		this.i18n = this.config.i18n || {};
		this.items = {};
		this.selectedId = null;
		this.fitted = false;
		this.listEl = root.querySelector('.vbr-map__list');
		this.searchEl = root.querySelector('.vbr-map__search');
		this.metaEl = root.querySelector('.vbr-map__meta');
		this.mine = {};
		this.onlyMine = false;

		(this.config.mine || []).forEach(function (id) {
			this.mine[id] = true;
		}, this);

		var launch = (this.config.initial && this.config.initial.launch) || { lat: 51.4885, lng: -2.58 };

		this.map = L.map(root.querySelector('.vbr-map__canvas'), { scrollWheelZoom: false })
			.setView([launch.lat, launch.lng], this.config.zoom || 7);

		L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		}).addTo(this.map);

		// Enable scroll zoom only after the user interacts, so the page still scrolls.
		this.map.once('focus click', function () {
			this.map.scrollWheelZoom.enable();
		}, this);

		this.trailPane = this.map.createPane('vbr-trails');
		this.trailPane.style.zIndex = 410;

		this.map.on('click', function () {
			this.select(null);
		}, this);

		if (this.searchEl) {
			this.searchEl.addEventListener('input', this.filterList.bind(this));
		}

		root.querySelectorAll('[data-vbr-show]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				this.showOnlyMine(btn.getAttribute('data-vbr-show') === 'mine');
			}.bind(this));
		}, this);

		var params = new URLSearchParams(window.location.search);
		var wanted = parseInt(params.get('balloon'), 10);

		if (this.config.initial) {
			this.update(this.config.initial);
		}

		var own = this.firstMine();

		if (wanted && this.items[wanted]) {
			this.select(wanted, true);
		} else if (own) {
			// Logged-in entrants open on their own balloon.
			this.select(own, true);
		} else {
			this.fitAll();
		}

		this.poll();
	}

	RaceMap.prototype.isMine = function (id) {
		return !!this.mine[id];
	};

	// The viewer's best-placed balloon (balloons arrive sorted by distance).
	RaceMap.prototype.firstMine = function () {
		var balloons = (this.data && this.data.balloons) || [];
		for (var i = 0; i < balloons.length; i++) {
			if (this.isMine(balloons[i].id)) {
				return balloons[i].id;
			}
		}
		return null;
	};

	RaceMap.prototype.showOnlyMine = function (on) {
		this.onlyMine = !!on;

		this.root.querySelectorAll('[data-vbr-show]').forEach(function (btn) {
			btn.setAttribute('aria-pressed', String((btn.getAttribute('data-vbr-show') === 'mine') === this.onlyMine));
		}, this);

		if (this.onlyMine && this.selectedId !== null && !this.isMine(this.selectedId)) {
			this.select(null);
		}

		this.applyStyles();
		this.filterList();

		if (this.onlyMine) {
			this.fitTo(Object.keys(this.mine));
		}
	};

	RaceMap.prototype.poll = function () {
		var self = this;
		var seconds = Math.max(15, this.config.refresh || 60);

		function load() {
			var url = self.config.endpoint + (self.config.endpoint.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
			fetch(url, { cache: 'no-store', credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (data) {
					if (data) {
						self.update(data);
					}
				})
				.catch(function () { /* Keep the last good view. */ });
		}

		// Refresh straight away in case the page HTML came from a cache.
		load();
		this.timer = window.setInterval(function () {
			if (!document.hidden) {
				load();
			}
		}, seconds * 1000);
	};

	RaceMap.prototype.update = function (data) {
		var self = this;
		var seen = {};

		this.setLaunch(data.launch);

		(data.balloons || []).forEach(function (b) {
			seen[b.id] = true;
			if (self.items[b.id]) {
				self.updateItem(self.items[b.id], b);
			} else {
				self.items[b.id] = self.createItem(b);
			}
		});

		Object.keys(this.items).forEach(function (id) {
			if (!seen[id]) {
				self.removeItem(self.items[id]);
				delete self.items[id];
			}
		});

		this.data = data;
		this.renderList();
		this.applyStyles();
		this.renderMeta(data);
	};

	RaceMap.prototype.setLaunch = function (launch) {
		if (!launch) {
			return;
		}

		var latlng = [launch.lat, launch.lng];

		if (!this.launchMarker) {
			this.launchMarker = L.marker(latlng, {
				icon: L.divIcon({
					className: 'vbr-launch-icon',
					html: '<svg viewBox="0 0 24 28" aria-hidden="true"><path d="M4 2v25" stroke="#12324a" stroke-width="2" stroke-linecap="round"/><path d="M5 3h15l-3.5 4.5L20 12H5z" fill="#e26a2c" stroke="#b5501d" stroke-width="1" stroke-linejoin="round"/></svg>',
					iconSize: [24, 28],
					iconAnchor: [4, 27]
				}),
				keyboard: false,
				zIndexOffset: -100
			}).addTo(this.map);
		} else {
			this.launchMarker.setLatLng(latlng);
		}

		this.launchMarker.bindTooltip((this.i18n.launch || 'Launch') + (launch.label ? ': ' + launch.label : ''));
	};

	RaceMap.prototype.createItem = function (b) {
		var item = { data: b };

		item.line = L.polyline(b.trail, {
			pane: 'vbr-trails',
			bubblingMouseEvents: false,
			interactive: true,
			lineCap: 'round',
			lineJoin: 'round'
		}).addTo(this.map);

		item.line.on('click', function () {
			this.select(b.id);
		}, this);

		this.buildMarker(item);

		return item;
	};

	RaceMap.prototype.buildMarker = function (item) {
		var self = this;

		if (item.marker) {
			item.marker.remove();
		}

		item.iconKey = null;
		item.marker = L.marker([item.data.lat, item.data.lng], {
			icon: this.balloonIcon(item.data, false),
			bubblingMouseEvents: false,
			riseOnHover: true,
			title: item.data.name
		});

		item.marker.bindPopup(function () { return self.popupContent(item.data); });
		item.marker.on('click', function () {
			self.select(item.data.id);
		});
		item.marker.addTo(this.map);
	};

	RaceMap.prototype.balloonIcon = function (b, selected) {
		var width = (b.logo ? 34 : 22) * (selected ? 1.35 : 1);
		var height = width * 1.5;
		var html = '<span class="vbr-balloon' + (b.logo ? ' vbr-balloon--logo' : '') + (b.burst ? ' is-burst' : '') + '">' + balloonSvg(b.hex, b.burst);

		// Logo balloons show the picture inside the balloon body.
		if (b.logo && !b.burst) {
			html += '<span class="vbr-balloon__logo"><img src="' + encodeURI(b.logo) + '" alt="" /></span>';
		}

		return L.divIcon({
			className: 'vbr-balloon-icon' + (selected ? ' is-selected' : '') + (this.isMine(b.id) ? ' is-mine' : ''),
			html: html + '</span>',
			iconSize: [width, height],
			iconAnchor: [width / 2, height],
			popupAnchor: [0, -height * 0.85]
		});
	};

	RaceMap.prototype.updateItem = function (item, b) {
		item.data = b;
		item.line.setLatLngs(b.trail);
		item.marker.setLatLng([b.lat, b.lng]);

		if (item.marker.isPopupOpen()) {
			item.marker.setPopupContent(this.popupContent(b));
		}
	};

	RaceMap.prototype.removeItem = function (item) {
		item.line.remove();
		item.marker.remove();
		if (this.selectedId === item.data.id) {
			this.selectedId = null;
		}
	};

	RaceMap.prototype.popupContent = function (b) {
		var i18n = this.i18n;
		var wrap = el('div', 'vbr-popup');
		var title = el('strong', 'vbr-popup__title', b.name);

		title.prepend(miniBalloon(b));
		wrap.appendChild(title);

		[
			[i18n.owner || 'Owner', b.owner],
			[i18n.distance || 'Distance', formatKm(b.distance) + ' ' + (i18n.km || 'km')],
			[i18n.status || 'Status', this.balloonStatus(b)]
		].forEach(function (row) {
			if (!row[1]) {
				return;
			}
			var line = el('div', 'vbr-popup__row');
			line.appendChild(el('span', 'vbr-popup__label', row[0] + ': '));
			line.appendChild(document.createTextNode(row[1]));
			wrap.appendChild(line);
		});

		return wrap;
	};

	RaceMap.prototype.raceStatus = function () {
		return this.data && this.data.race ? this.data.race.status : null;
	};

	RaceMap.prototype.balloonStatus = function (b) {
		var i18n = this.i18n;
		var stage = this.raceStatus();

		if (b.burst) {
			return i18n.burst || 'Burst';
		}
		if (stage === 'upcoming' || stage === 'on_sale') {
			return i18n.waiting || 'Waiting for launch';
		}
		if (stage === 'finished') {
			return (this.data.race && this.data.race.status_label) || 'Finished';
		}
		return i18n.active || 'Flying';
	};

	RaceMap.prototype.applyStyles = function () {
		var self = this;
		var hasSelection = this.selectedId !== null && !!this.items[this.selectedId];

		Object.keys(this.items).forEach(function (id) {
			var item = self.items[id];
			var b = item.data;
			var selected = hasSelection && b.id === self.selectedId;
			var hex = b.burst ? BURST_HEX : b.hex;
			var shown = !self.onlyMine || self.isMine(b.id);

			// "My balloons" hides everyone else's balloon and trail.
			[item.line, item.marker].forEach(function (layer) {
				if (shown && !self.map.hasLayer(layer)) {
					layer.addTo(self.map);
				} else if (!shown && self.map.hasLayer(layer)) {
					layer.remove();
				}
			});

			if (!shown) {
				return;
			}

			item.line.setStyle({
				color: hex,
				weight: selected ? 5 : 2,
				opacity: selected ? 1 : (hasSelection ? 0.12 : 0.45),
				dashArray: b.burst ? '6 6' : null
			});

			var lineEl = item.line.getElement();
			if (lineEl) {
				lineEl.classList.toggle('vbr-trail--selected', selected);
			}

			if (selected) {
				item.line.bringToFront();
			}

			// Only rebuild the icon when something it shows has changed.
			var key = [b.hex, b.burst, b.logo, selected, self.isMine(b.id)].join('|');
			if (key !== item.iconKey) {
				item.marker.setIcon(self.balloonIcon(b, selected));
				item.iconKey = key;
			}
			item.marker.setOpacity(hasSelection && !selected ? 0.5 : 1);
			item.marker.setZIndexOffset(selected ? 1000 : (self.isMine(b.id) ? 500 : 0));
		});

		if (this.listEl) {
			this.listEl.querySelectorAll('[data-id]').forEach(function (li) {
				var on = hasSelection && parseInt(li.getAttribute('data-id'), 10) === self.selectedId;
				li.classList.toggle('is-selected', on);
				li.setAttribute('aria-current', on ? 'true' : 'false');
			});
		}
	};

	RaceMap.prototype.select = function (id, focus) {
		id = id === null || id === undefined ? null : parseInt(id, 10);

		if (id !== null && !this.items[id]) {
			return;
		}

		// Picking someone else's balloon (e.g. from the leaderboard) shows everyone again.
		if (id !== null && this.onlyMine && !this.isMine(id)) {
			this.showOnlyMine(false);
		}

		this.selectedId = id;
		this.applyStyles();
		this.syncUrl();

		if (id === null) {
			this.map.closePopup();
			return;
		}

		var item = this.items[id];

		if (focus) {
			var bounds = item.line.getBounds();
			if (bounds.isValid() && item.data.trail.length > 1) {
				this.map.fitBounds(bounds.pad(0.25), { maxZoom: 12 });
			} else {
				this.map.setView([item.data.lat, item.data.lng], Math.max(this.map.getZoom(), 9));
			}
			item.marker.openPopup();
		}

		if (this.listEl) {
			var li = this.listEl.querySelector('[data-id="' + id + '"]');
			if (li && li.scrollIntoView) {
				li.scrollIntoView({ block: 'nearest' });
			}
		}
	};

	RaceMap.prototype.syncUrl = function () {
		if (!window.history || !window.history.replaceState) {
			return;
		}
		var url = new URL(window.location.href);
		if (this.selectedId === null) {
			url.searchParams.delete('balloon');
		} else {
			url.searchParams.set('balloon', String(this.selectedId));
		}
		window.history.replaceState(null, '', url.toString());
	};

	RaceMap.prototype.fitAll = function () {
		this.fitTo(Object.keys(this.items), true);
	};

	RaceMap.prototype.fitTo = function (ids, withLaunch) {
		var points = [];
		var self = this;

		ids.forEach(function (id) {
			if (self.items[id]) {
				points = points.concat(self.items[id].data.trail);
			}
		});

		if (withLaunch && this.data && this.data.launch) {
			points.push([this.data.launch.lat, this.data.launch.lng]);
		}

		if (points.length > 1) {
			var bounds = L.latLngBounds(points);
			if (bounds.isValid()) {
				this.map.fitBounds(bounds.pad(0.15), { maxZoom: this.config.zoom || 10 });
			}
		}
	};

	RaceMap.prototype.renderList = function () {
		if (!this.listEl) {
			return;
		}

		var self = this;
		var balloons = (this.data && this.data.balloons) || [];

		this.listEl.innerHTML = '';

		if (!balloons.length) {
			this.listEl.appendChild(el('li', 'vbr-map__empty', this.i18n.empty || 'No balloons yet.'));
			return;
		}

		balloons.forEach(function (b) {
			var li = el('li', 'vbr-map__item' + (b.burst ? ' is-burst' : '') + (self.isMine(b.id) ? ' is-mine' : ''));
			var btn = el('button', 'vbr-map__item-btn');

			li.setAttribute('data-id', b.id);
			li.setAttribute('data-search', (b.name + ' ' + (b.owner || '')).toLowerCase());
			btn.type = 'button';

			btn.appendChild(el('span', 'vbr-map__rank', b.rank ? String(b.rank) : '–'));
			btn.appendChild(miniBalloon(b));
			btn.appendChild(el('span', 'vbr-map__name', b.name));
			if (self.isMine(b.id)) {
				btn.appendChild(el('span', 'vbr-map__yours', self.i18n.yours || 'Yours'));
			}
			btn.appendChild(el('span', 'vbr-map__km', formatKm(b.distance) + ' ' + (self.i18n.km || 'km')));

			btn.addEventListener('click', function () {
				self.select(self.selectedId === b.id ? null : b.id, self.selectedId !== b.id);
			});

			li.appendChild(btn);
			self.listEl.appendChild(li);
		});

		this.filterList();
	};

	RaceMap.prototype.filterList = function () {
		if (!this.listEl) {
			return;
		}
		var self = this;
		var q = this.searchEl ? this.searchEl.value.toLowerCase().trim() : '';
		this.listEl.querySelectorAll('[data-search]').forEach(function (li) {
			var mineOk = !self.onlyMine || self.isMine(parseInt(li.getAttribute('data-id'), 10));
			li.hidden = !mineOk || (!!q && li.getAttribute('data-search').indexOf(q) === -1);
		});
	};

	RaceMap.prototype.renderMeta = function (data) {
		if (!this.metaEl) {
			return;
		}

		var i18n = this.i18n;
		var stages = i18n.stage || {};
		var race = data.race;
		var parts = [];

		function when(iso) {
			return iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '';
		}

		if (!race) {
			this.metaEl.textContent = i18n.noRace || '';
			return;
		}

		parts.push(race.name);

		var stageText = {
			upcoming: (stages.upcoming || 'Entries open %s').replace('%s', when(race.entries_open)),
			on_sale: (stages.on_sale || 'Launches %s').replace('%s', when(race.launch_at)),
			flying: stages.flying || 'Flying now',
			finished: (stages.finished || 'Finished %s').replace('%s', when(race.end_at))
		}[race.status];

		if (stageText) {
			parts.push(stageText);
		}

		if (race.status === 'flying' && data.updated_at) {
			parts.push((i18n.updated || 'Last update') + ': ' + when(data.updated_at));
		}

		this.metaEl.textContent = parts.join(' · ');
		this.root.setAttribute('data-race-status', race.status);
	};

	var maps = [];

	document.querySelectorAll('[data-vbr-map]').forEach(function (root) {
		try {
			maps.push(new RaceMap(root));
		} catch (e) {
			if (window.console) {
				window.console.error('Balloon map failed to start', e);
			}
		}
	});

	// Leaderboard rows (or any [data-vbr-balloon] element) select on a map on the same page.
	document.addEventListener('click', function (e) {
		var target = e.target.closest ? e.target.closest('[data-vbr-balloon]') : null;
		if (!target || !maps.length) {
			return;
		}
		var id = parseInt(target.getAttribute('data-vbr-balloon'), 10);
		if (!maps[0].items[id]) {
			return;
		}
		e.preventDefault();
		maps[0].select(id, true);
		maps[0].root.scrollIntoView({ behavior: 'smooth', block: 'center' });
	});

	window.vbrMaps = maps;
})();
