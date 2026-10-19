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

		var params = new URLSearchParams(window.location.search);
		var wanted = parseInt(params.get('balloon'), 10);

		if (this.config.initial) {
			this.update(this.config.initial);
		}

		if (wanted && this.items[wanted]) {
			this.select(wanted, true);
		} else {
			this.fitAll();
		}

		this.poll();
	}

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
				icon: L.divIcon({ className: 'vbr-launch-icon', html: '<span aria-hidden="true">⚑</span>', iconSize: [28, 28], iconAnchor: [6, 26] }),
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
		var b = item.data;
		var self = this;

		if (item.marker) {
			item.marker.remove();
		}

		if (b.logo) {
			item.marker = L.marker([b.lat, b.lng], {
				icon: this.logoIcon(b, false),
				bubblingMouseEvents: false,
				riseOnHover: true,
				title: b.name
			});
		} else {
			item.marker = L.circleMarker([b.lat, b.lng], {
				bubblingMouseEvents: false,
				radius: 7,
				weight: 2,
				color: '#ffffff',
				fillOpacity: 1
			});
		}

		item.markerType = b.logo ? 'logo:' + b.logo : 'dot';
		item.marker.bindPopup(function () { return self.popupContent(item.data); });
		item.marker.on('click', function () {
			self.select(item.data.id);
		});
		item.marker.addTo(this.map);
	};

	RaceMap.prototype.logoIcon = function (b, selected) {
		var size = selected ? 46 : 34;
		var border = b.burst ? BURST_HEX : b.hex;
		var html = '<span class="vbr-logo-pin' + (b.burst ? ' is-burst' : '') + '" style="border-color:' + border + '"><img src="' + encodeURI(b.logo) + '" alt="" /></span>';

		return L.divIcon({
			className: 'vbr-logo-icon' + (selected ? ' is-selected' : ''),
			html: html,
			iconSize: [size, size],
			iconAnchor: [size / 2, size / 2],
			popupAnchor: [0, -size / 2]
		});
	};

	RaceMap.prototype.updateItem = function (item, b) {
		var markerType = b.logo ? 'logo:' + b.logo : 'dot';

		item.data = b;
		item.line.setLatLngs(b.trail);

		if (markerType !== item.markerType) {
			this.buildMarker(item);
		} else {
			item.marker.setLatLng([b.lat, b.lng]);
		}

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
		var dot = el('span', 'vbr-popup__dot');

		dot.style.background = b.hex;
		title.prepend(dot);
		wrap.appendChild(title);

		[
			[i18n.owner || 'Owner', b.owner],
			[i18n.distance || 'Distance', formatKm(b.distance) + ' ' + (i18n.km || 'km')],
			[i18n.status || 'Status', b.burst ? (i18n.burst || 'Burst') : (i18n.active || 'Flying')]
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

	RaceMap.prototype.applyStyles = function () {
		var self = this;
		var hasSelection = this.selectedId !== null && !!this.items[this.selectedId];

		Object.keys(this.items).forEach(function (id) {
			var item = self.items[id];
			var b = item.data;
			var selected = hasSelection && b.id === self.selectedId;
			var hex = b.burst ? BURST_HEX : b.hex;

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

			if (item.markerType === 'dot') {
				item.marker.setStyle({
					radius: selected ? 11 : 7,
					fillColor: hex,
					color: selected ? '#12324a' : '#ffffff',
					weight: selected ? 3 : 2,
					opacity: hasSelection && !selected ? 0.5 : 1,
					fillOpacity: hasSelection && !selected ? 0.45 : 1
				});
				if (selected) {
					item.marker.bringToFront();
				}
			} else {
				item.marker.setIcon(self.logoIcon(b, selected));
				item.marker.setOpacity(hasSelection && !selected ? 0.55 : 1);
				item.marker.setZIndexOffset(selected ? 1000 : 0);
			}
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
		var points = [];
		var self = this;

		Object.keys(this.items).forEach(function (id) {
			points = points.concat(self.items[id].data.trail);
		});

		if (this.data && this.data.launch) {
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
			var li = el('li', 'vbr-map__item' + (b.burst ? ' is-burst' : ''));
			var btn = el('button', 'vbr-map__item-btn');
			var dot = el('span', 'vbr-map__dot');

			li.setAttribute('data-id', b.id);
			li.setAttribute('data-search', (b.name + ' ' + (b.owner || '')).toLowerCase());
			btn.type = 'button';
			dot.style.background = b.burst ? BURST_HEX : b.hex;

			btn.appendChild(el('span', 'vbr-map__rank', b.rank ? String(b.rank) : '–'));
			btn.appendChild(dot);
			btn.appendChild(el('span', 'vbr-map__name', b.name));
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
		if (!this.listEl || !this.searchEl) {
			return;
		}
		var q = this.searchEl.value.toLowerCase().trim();
		this.listEl.querySelectorAll('[data-search]').forEach(function (li) {
			li.hidden = !!q && li.getAttribute('data-search').indexOf(q) === -1;
		});
	};

	RaceMap.prototype.renderMeta = function (data) {
		if (!this.metaEl) {
			return;
		}
		this.metaEl.textContent = data.updated_at
			? (this.i18n.updated || 'Last update') + ': ' + new Date(data.updated_at).toLocaleString()
			: '';
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
