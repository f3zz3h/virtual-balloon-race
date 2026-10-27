/* Virtual Balloon Race — balloon drawing shared by the map and the product page. */
(function () {
	'use strict';

	var BURST_HEX = '#8a8f98';

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

	function escapeAttr(value) {
		return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
	}

	var clipCount = 0;
	var BODY_PATH = 'M12 1C5.9 1 2 5.9 2 11.6 2 18.2 7.3 23.6 12 25c4.7-1.4 10-6.8 10-13.4C22 5.9 18.1 1 12 1z';

	// A balloon drawn in a 24×36 box. The string ends at the bottom centre, which sits on the balloon's position.
	// With a logo, the picture fills the balloon body over the balloon colour: cropped to fit,
	// or shown whole when `fit` is "contain" (very wide or tall pictures).
	function balloonSvg(hex, burst, logo, fit) {
		var string = '<path d="M12 26.8c-1.8 2.2 1.8 4.4 0 6.6-.6.8-.6 1.6-.2 2.4" fill="none" stroke="#5b6b78" stroke-width="0.9" stroke-linecap="round"/>';

		if (burst) {
			return '<svg class="lvbr-balloon__svg" viewBox="0 0 24 36" aria-hidden="true">' +
				'<path d="M12 22.5c-1.8 2.6 1.8 5.2 0 7.8-.6 1-.6 2.6-.2 5" fill="none" stroke="#5b6b78" stroke-width="0.9" stroke-linecap="round"/>' +
				'<path d="M12 4.5l1.7 4.6 4.5-2.1-1.9 4.6 4.8 1.3-4.4 2.3 2.8 4.1-4.8-.9.1 4.9L12 19.6l-2.8 3.7.1-4.9-4.8.9 2.8-4.1-4.4-2.3 4.8-1.3L5.8 7l4.5 2.1z" fill="' + BURST_HEX + '" stroke="#6b7079" stroke-width="0.8" stroke-linejoin="round"/>' +
				'</svg>';
		}

		var fill = safeHex(hex);
		var edge = shade(fill, -0.35);

		var picture = '';

		if (logo) {
			var clipId = 'lvbr-balloon-clip-' + (++clipCount);
			picture = '<clipPath id="' + clipId + '"><path d="' + BODY_PATH + '"/></clipPath>' +
				'<image href="' + escapeAttr(logo) + '" x="2" y="1" width="20" height="24" preserveAspectRatio="xMidYMid ' + (fit === 'contain' ? 'meet' : 'slice') + '" clip-path="url(#' + clipId + ')"/>';
		}

		return '<svg class="lvbr-balloon__svg" viewBox="0 0 24 36" aria-hidden="true">' +
			string +
			'<path d="' + BODY_PATH + '" fill="' + fill + '"/>' +
			picture +
			'<path d="' + BODY_PATH + '" fill="none" stroke="' + edge + '" stroke-width="' + (logo ? 1.4 : 1) + '"/>' +
			'<path d="M10.4 26.8 12 24.6l1.6 2.2z" fill="' + edge + '" stroke="' + edge + '" stroke-width="0.6" stroke-linejoin="round"/>' +
			'<ellipse cx="7.6" cy="8" rx="2.1" ry="3.6" transform="rotate(25 7.6 8)" fill="#fff" fill-opacity="' + (logo ? '.3' : '.45') + '"/>' +
			'</svg>';
	}

	function escapeText(value) {
		return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	var ENVELOPE = 'M50 4C22 4 6 26 6 50c0 24 24 42 32 54h24c8-12 32-30 32-54C94 26 78 4 50 4z';

	// A sponsor's hot-air balloon in a 100×140 box; the basket's base is the bottom centre.
	// s: { style: stripes|panel|full, color1, color2, band, logo, fit }. The logo styles need a logo.
	function hotAirSvg(s) {
		var c1 = safeHex(s.color1);
		var c2 = safeHex(s.color2);
		var edge = shade(c1, -0.4);
		var clipId = 'lvbr-hot-air-clip-' + (++clipCount);
		var body;

		if (s.style === 'full' && s.logo) {
			body = '<clipPath id="' + clipId + '"><path d="' + ENVELOPE + '"/></clipPath>' +
				'<path d="' + ENVELOPE + '" fill="' + c1 + '"/>' +
				'<image href="' + escapeAttr(s.logo) + '" x="6" y="4" width="88" height="100" preserveAspectRatio="xMidYMid ' + (s.fit === 'contain' ? 'meet' : 'slice') + '" clip-path="url(#' + clipId + ')"/>';
		} else {
			body = '<path d="' + ENVELOPE + '" fill="' + c1 + '"/>' +
				'<path d="M50 4C36 4 28 28 28 50c0 22 10 40 14 54h16c4-14 14-32 14-54C72 28 64 4 50 4z" fill="' + c2 + '"/>' +
				'<path d="M50 4C44 4 40 28 40 50c0 22 4 40 6 54h8c2-14 6-32 6-54C60 28 56 4 50 4z" fill="' + c1 + '"/>';

			if (s.style === 'panel' && s.logo) {
				body += '<clipPath id="' + clipId + '"><circle cx="50" cy="48" r="16"/></clipPath>' +
					'<circle cx="50" cy="48" r="18" fill="#fff" stroke="' + edge + '" stroke-width="1.5"/>' +
					'<image href="' + escapeAttr(s.logo) + '" x="34" y="32" width="32" height="32" preserveAspectRatio="xMidYMid meet" clip-path="url(#' + clipId + ')"/>';
			} else if (s.band) {
				var size = s.band.length > 9 ? 7 : (s.band.length > 7 ? 8.5 : 10);
				body += '<rect x="14" y="40" width="72" height="20" rx="4" fill="#fff" stroke="' + edge + '" stroke-width="1.5"/>' +
					'<text x="50" y="54" text-anchor="middle" font-size="' + size + '" font-weight="800" fill="' + edge + '" font-family="system-ui, sans-serif">' + escapeText(s.band) + '</text>';
			}
		}

		return '<svg class="lvbr-hot-air__svg" viewBox="0 0 100 140" aria-hidden="true">' +
			body +
			'<path d="' + ENVELOPE + '" fill="none" stroke="' + edge + '" stroke-width="2"/>' +
			'<path d="M38 104h24l-4 7H42z" fill="' + edge + '"/>' +
			'<path d="M43 111l2 9M57 111l-2 9" stroke="#5b4630" stroke-width="1.5"/>' +
			'<rect x="42" y="120" width="16" height="12" rx="2" fill="#8b5a2b"/>' +
			'</svg>';
	}

	window.lvbrBalloon = {
		BURST_HEX: BURST_HEX,
		hotAir: hotAirSvg,
		svg: balloonSvg
	};
})();
