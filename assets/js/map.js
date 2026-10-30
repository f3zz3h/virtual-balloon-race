/* Virtual Balloon Race — live Leaflet map with trails. */
(function () {
	'use strict';

	if (typeof L === 'undefined' || !window.lvbrBalloon) {
		return;
	}

	var BURST_HEX = window.lvbrBalloon.BURST_HEX;

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

	var balloonSvg = window.lvbrBalloon.svg;

	// Small balloon for the side list and popups.
	function miniBalloon(b) {
		var span = el('span', 'lvbr-mini-balloon');
		span.innerHTML = balloonSvg(b.hex, b.burst);
		return span;
	}

	function formatKm(value) {
		return Number(value).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
	}

	function RaceMap(root) {
		this.root = root;
		this.config = JSON.parse(root.getAttribute('data-config') || '{}');
		this.i18n = this.config.i18n || {};
		this.items = {};
		this.selectedId = null;
		this.fitted = false;
		this.listEl = root.querySelector('.lvbr-map__list');
		this.searchEl = root.querySelector('.lvbr-map__search');
		this.metaEl = root.querySelector('.lvbr-map__meta');
		this.mine = {};
		this.onlyMine = false;
		this.teams = {};
		this.teamFilter = null;
		this.teamEl = root.querySelector('.lvbr-map__team');

		(this.config.mine || []).forEach(function (id) {
			this.mine[id] = true;
		}, this);

		var launch = (this.config.initial && this.config.initial.launch) || { lat: 51.4447, lng: -2.638 };

		this.map = L.map(root.querySelector('.lvbr-map__canvas'), { scrollWheelZoom: false })
			.setView([launch.lat, launch.lng], this.config.zoom || 7);

		window.lvbrServices.tileLayer().addTo(this.map);

		// Enable scroll zoom only after the user interacts, so the page still scrolls.
		this.map.once('focus click', function () {
			this.map.scrollWheelZoom.enable();
		}, this);

		this.trailPane = this.map.createPane('lvbr-trails');
		this.trailPane.style.zIndex = 410;

		this.map.on('click', function () {
			this.select(null);
		}, this);

		if (this.searchEl) {
			this.searchEl.addEventListener('input', this.filterList.bind(this));
		}

		if (this.teamEl) {
			this.teamEl.addEventListener('change', function () {
				this.showTeam(this.teamEl.value ? parseInt(this.teamEl.value, 10) : null);
			}.bind(this));
		}

		root.querySelectorAll('[data-lvbr-show]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				this.showOnlyMine(btn.getAttribute('data-lvbr-show') === 'mine');
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

	// Whether a balloon passes the My balloons and team filters.
	RaceMap.prototype.isShown = function (b) {
		return (!this.onlyMine || this.isMine(b.id)) && (this.teamFilter === null || b.team === this.teamFilter);
	};

	RaceMap.prototype.showTeam = function (teamId) {
		var self = this;

		this.teamFilter = teamId;
		if (this.teamEl) {
			this.teamEl.value = teamId === null ? '' : String(teamId);
		}

		if (this.selectedId !== null && this.items[this.selectedId] && !this.isShown(this.items[this.selectedId].data)) {
			this.select(null);
		}

		this.applyStyles();
		this.filterList();

		if (teamId !== null) {
			this.fitTo(Object.keys(this.items).filter(function (id) {
				return self.isShown(self.items[id].data);
			}));
		}
	};

	RaceMap.prototype.teamName = function (b) {
		return b.team && this.teams[b.team] ? this.teams[b.team].name : '';
	};

	RaceMap.prototype.showOnlyMine = function (on) {
		this.onlyMine = !!on;

		this.root.querySelectorAll('[data-lvbr-show]').forEach(function (btn) {
			btn.setAttribute('aria-pressed', String((btn.getAttribute('data-lvbr-show') === 'mine') === this.onlyMine));
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
			var url = self.config.endpoint;
			var wanted = self.wantedTrails();
			if (wanted.length) {
				url += (url.indexOf('?') === -1 ? '?' : '&') + 'trails=' + wanted.join(',');
			}
			// "no-cache" revalidates with the ETag: until the balloons move, the server answers "not modified".
			fetch(url, { cache: 'no-cache', credentials: 'same-origin' })
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
		this.setSponsors(data.sponsors || []);

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
		this.teams = {};
		(data.teams || []).forEach(function (team) {
			self.teams[team.id] = team;
		});
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
					className: 'lvbr-launch-icon',
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

		this.launchMarker.unbindTooltip();
		this.launchMarker.unbindPopup();

		if (launch.sponsor) {
			// Branded launch flag: the sponsor's name always shows, and the flag links to them.
			this.sponsorViewed(launch.sponsor.id, 'launch_flag');
			this.launchMarker.bindTooltip((this.i18n.launch || 'Launch') + ': ' + launch.sponsor.name, {
				permanent: true,
				direction: 'right',
				offset: [14, -16],
				className: 'lvbr-launch-sponsor'
			});
			if (launch.sponsor.url) {
				var popup = el('div', 'lvbr-popup lvbr-popup--sponsor');
				popup.appendChild(el('strong', 'lvbr-popup__title', launch.sponsor.name));
				popup.appendChild(this.sponsorLink(launch.sponsor.url, launch.sponsor.id, 'launch_flag'));
				this.launchMarker.bindPopup(popup);
			}
			return;
		}

		this.launchMarker.bindTooltip((this.i18n.launch || 'Launch') + (launch.label ? ': ' + launch.label : ''));
	};

	RaceMap.prototype.sponsorLink = function (url, id, placement) {
		var a = el('a', 'lvbr-popup__link', this.i18n.visit || 'Visit their website');
		a.href = url;
		a.target = '_blank';
		a.rel = 'sponsored noopener';
		a.setAttribute('data-lvbr-sponsor', id);
		a.setAttribute('data-lvbr-placement', placement);
		return a;
	};

	// Tell the views and clicks report a sponsor was shown (sponsor-stats.js, when loaded).
	RaceMap.prototype.sponsorViewed = function (id, placement) {
		if (window.lvbrSponsorStats && window.lvbrSponsorStats.view) {
			window.lvbrSponsorStats.view(String(id), placement);
		} else {
			// The report script may load after the map draws; it picks these up when it starts.
			(window.lvbrSponsorStatsQueue = window.lvbrSponsorStatsQueue || []).push([String(id), placement]);
		}
	};

	// Sponsor hot-air balloons: they fly with the race but are never ranked.
	RaceMap.prototype.setSponsors = function (list) {
		var self = this;
		var seen = {};

		this.sponsorItems = this.sponsorItems || {};

		list.forEach(function (s) {
			var item = self.sponsorItems[s.id];
			var w = s.size === 'large' ? 54 : 40;
			var h = Math.round(w * 1.4);
			var key = [s.style, s.color1, s.color2, s.band, s.logo, s.fit, s.size].join('|');

			seen[s.id] = true;

			if (!item) {
				item = self.sponsorItems[s.id] = {};
				item.line = L.polyline(s.trail, {
					pane: 'lvbr-trails',
					color: s.color1,
					weight: 2,
					opacity: 0.45,
					dashArray: '2 6',
					interactive: false
				}).addTo(self.map);
				item.marker = L.marker([s.lat, s.lng], {
					zIndexOffset: -50,
					riseOnHover: true,
					title: s.name,
					alt: s.name
				}).addTo(self.map);
				item.marker.bindPopup(function () {
					return self.sponsorPopup(item.data);
				});
			}

			item.data = s;
			self.sponsorViewed(s.id, 'hot_air');
			if (item.key !== key) {
				item.key = key;
				item.marker.setIcon(L.divIcon({
					className: 'lvbr-hot-air',
					html: window.lvbrBalloon.hotAir(s),
					iconSize: [w, h],
					iconAnchor: [w / 2, h],
					popupAnchor: [0, -h + 8]
				}));
			}
			item.marker.setLatLng([s.lat, s.lng]);
			item.line.setLatLngs(s.trail);
		});

		Object.keys(this.sponsorItems).forEach(function (id) {
			if (!seen[id]) {
				self.sponsorItems[id].marker.remove();
				self.sponsorItems[id].line.remove();
				delete self.sponsorItems[id];
			}
		});
	};

	RaceMap.prototype.sponsorPopup = function (s) {
		var wrap = el('div', 'lvbr-popup lvbr-popup--sponsor');

		wrap.appendChild(el('strong', 'lvbr-popup__title', s.name));
		wrap.appendChild(el('div', 'lvbr-popup__row', this.i18n.sponsorNote || 'Sponsor balloon · flying with the race, not racing'));
		if (s.url) {
			wrap.appendChild(this.sponsorLink(s.url, s.id, 'hot_air'));
		}

		return wrap;
	};

	RaceMap.prototype.createItem = function (b) {
		var item = { data: b };

		item.line = L.polyline(b.trail || [], {
			pane: 'lvbr-trails',
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
		var html = '<span class="lvbr-balloon' + (b.logo ? ' lvbr-balloon--logo' : '') + (b.burst ? ' is-burst' : '') + '">' + balloonSvg(b.hex, b.burst, b.logo, b.logo_fit);

		return L.divIcon({
			className: 'lvbr-balloon-icon' + (selected ? ' is-selected' : '') + (this.isMine(b.id) ? ' is-mine' : ''),
			html: html + '</span>',
			iconSize: [width, height],
			iconAnchor: [width / 2, height],
			popupAnchor: [0, -height * 0.85]
		});
	};

	// Big races only send the leaders' trails: ask for the viewer's balloons and the selected one too.
	RaceMap.prototype.wantedTrails = function () {
		if (!this.data || this.data.trails !== 'some') {
			return [];
		}
		var ids = Object.keys(this.mine);
		if (this.selectedId !== null && ids.indexOf(String(this.selectedId)) === -1) {
			ids.push(String(this.selectedId));
		}
		return ids.slice(0, 50);
	};

	// Load one balloon's trail (big races), then draw it.
	RaceMap.prototype.loadTrail = function (id, focus) {
		var self = this;
		var url = this.config.trailUrl + (this.config.trailUrl.indexOf('?') === -1 ? '?' : '&') + 'id=' + id;

		fetch(url, { cache: 'no-cache', credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (res) {
				var item = res && self.items[res.id];
				if (!item) {
					return;
				}
				item.data.trail = res.trail;
				item.line.setLatLngs(res.trail);
				self.applyStyles();
				if (focus && self.selectedId === res.id && res.trail.length > 1) {
					self.map.fitBounds(item.line.getBounds().pad(0.25), { maxZoom: 12 });
				}
			})
			.catch(function () { /* No trail this time; the marker still shows. */ });
	};

	RaceMap.prototype.updateItem = function (item, b) {
		item.data = b;
		item.line.setLatLngs(b.trail || []);
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
		var wrap = el('div', 'lvbr-popup');
		var title = el('strong', 'lvbr-popup__title', b.name);

		title.prepend(miniBalloon(b));
		wrap.appendChild(title);

		var km = ' ' + (i18n.km || 'km');
		var fromStart = [i18n.fromStart || 'From launch', b.from_start !== undefined ? formatKm(b.from_start) + km : ''];
		var travelled = [i18n.travelled || 'Travelled', b.travelled !== undefined ? formatKm(b.travelled) + km : ''];
		// The measure that decides the race comes first.
		var distances = this.data && this.data.ranking === 'travelled' ? [travelled, fromStart] : [fromStart, travelled];

		[
			[i18n.owner || 'Owner', b.owner],
			[i18n.team || 'Team', this.teamName(b)],
			distances[0],
			distances[1],
			[i18n.status || 'Status', this.balloonStatus(b)]
		].forEach(function (row) {
			if (!row[1]) {
				return;
			}
			var line = el('div', 'lvbr-popup__row');
			line.appendChild(el('span', 'lvbr-popup__label', row[0] + ': '));
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
			var shown = self.isShown(b);

			// "My balloons" and the team filter hide other balloons and trails.
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
				lineEl.classList.toggle('lvbr-trail--selected', selected);
			}

			if (selected) {
				item.line.bringToFront();
			}

			// Only rebuild the icon when something it shows has changed.
			var key = [b.hex, b.burst, b.logo, b.logo_fit, selected, self.isMine(b.id)].join('|');
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

		// Picking a balloon hidden by a filter (e.g. from the leaderboard) clears that filter.
		if (id !== null && this.onlyMine && !this.isMine(id)) {
			this.showOnlyMine(false);
		}
		if (id !== null && this.teamFilter !== null && this.items[id].data.team !== this.teamFilter) {
			this.showTeam(null);
		}

		this.selectedId = id;
		this.applyStyles();
		this.syncUrl();

		if (id === null) {
			this.map.closePopup();
			return;
		}

		var item = this.items[id];

		if (!item.data.trail && this.data && this.data.trails === 'some') {
			this.loadTrail(id, focus);
		}

		if (focus) {
			var bounds = item.line.getBounds();
			if (bounds.isValid() && item.data.trail && item.data.trail.length > 1) {
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
				points = points.concat(self.items[id].data.trail || [[self.items[id].data.lat, self.items[id].data.lng]]);
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
		// Widen the rank column for races with 1,000 or more balloons.
		this.listEl.style.setProperty('--lvbr-rank-width', Math.max(1.6, String(balloons.length).length * 0.6) + 'rem');

		// Sponsor balloons first, outside the ranking.
		((this.data && this.data.sponsors) || []).forEach(function (s) {
			var li = el('li', 'lvbr-map__item is-sponsor');
			var btn = el('button', 'lvbr-map__item-btn');

			btn.type = 'button';
			btn.appendChild(el('span', 'lvbr-map__tag', self.i18n.sponsor || 'Sponsor'));
			btn.appendChild(el('span', 'lvbr-map__name', s.name));
			btn.addEventListener('click', function () {
				var item = self.sponsorItems && self.sponsorItems[s.id];
				if (item) {
					self.map.panTo(item.marker.getLatLng());
					item.marker.openPopup();
				}
			});
			li.appendChild(btn);
			self.listEl.appendChild(li);
		});

		if (!balloons.length) {
			this.listEl.appendChild(el('li', 'lvbr-map__empty', this.i18n.empty || 'No balloons yet.'));
			return;
		}

		balloons.forEach(function (b) {
			var li = el('li', 'lvbr-map__item' + (b.burst ? ' is-burst' : '') + (self.isMine(b.id) ? ' is-mine' : ''));
			var btn = el('button', 'lvbr-map__item-btn');

			li.setAttribute('data-id', b.id);
			li.setAttribute('data-search', (b.name + ' ' + (b.owner || '') + ' ' + self.teamName(b)).toLowerCase());
			if (b.team && self.teams[b.team]) {
				li.classList.add('has-team');
				li.style.setProperty('--lvbr-team', self.teams[b.team].color);
				li.title = self.teamName(b);
			}
			btn.type = 'button';

			btn.appendChild(el('span', 'lvbr-map__rank', b.rank ? String(b.rank) : '–'));
			btn.appendChild(miniBalloon(b));
			btn.appendChild(el('span', 'lvbr-map__name', b.name));
			if (self.isMine(b.id)) {
				btn.appendChild(el('span', 'lvbr-map__yours', self.i18n.yours || 'Yours'));
			}
			btn.appendChild(el('span', 'lvbr-map__km', formatKm(b.distance) + ' ' + (self.i18n.km || 'km')));

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
			var item = self.items[li.getAttribute('data-id')];
			var ok = !item || self.isShown(item.data);
			li.hidden = !ok || (!!q && li.getAttribute('data-search').indexOf(q) === -1);
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

	document.querySelectorAll('[data-lvbr-map]').forEach(function (root) {
		try {
			maps.push(new RaceMap(root));
		} catch (e) {
			if (window.console) {
				window.console.error('Balloon map failed to start', e);
			}
		}
	});

	// Leaderboard rows (or any [data-lvbr-balloon] element) select on a map on the same page.
	document.addEventListener('click', function (e) {
		var target = e.target.closest ? e.target.closest('[data-lvbr-balloon]') : null;
		if (!target || !maps.length) {
			return;
		}
		var id = parseInt(target.getAttribute('data-lvbr-balloon'), 10);
		if (!maps[0].items[id]) {
			return;
		}
		e.preventDefault();
		maps[0].select(id, true);
		maps[0].root.scrollIntoView({ behavior: 'smooth', block: 'center' });
	});

	window.lvbrMaps = maps;
})();
