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
			return '<svg class="vbr-balloon__svg" viewBox="0 0 24 36" aria-hidden="true">' +
				'<path d="M12 22.5c-1.8 2.6 1.8 5.2 0 7.8-.6 1-.6 2.6-.2 5" fill="none" stroke="#5b6b78" stroke-width="0.9" stroke-linecap="round"/>' +
				'<path d="M12 4.5l1.7 4.6 4.5-2.1-1.9 4.6 4.8 1.3-4.4 2.3 2.8 4.1-4.8-.9.1 4.9L12 19.6l-2.8 3.7.1-4.9-4.8.9 2.8-4.1-4.4-2.3 4.8-1.3L5.8 7l4.5 2.1z" fill="' + BURST_HEX + '" stroke="#6b7079" stroke-width="0.8" stroke-linejoin="round"/>' +
				'</svg>';
		}

		var fill = safeHex(hex);
		var edge = shade(fill, -0.35);

		var picture = '';

		if (logo) {
			var clipId = 'vbr-balloon-clip-' + (++clipCount);
			picture = '<clipPath id="' + clipId + '"><path d="' + BODY_PATH + '"/></clipPath>' +
				'<image href="' + escapeAttr(logo) + '" x="2" y="1" width="20" height="24" preserveAspectRatio="xMidYMid ' + (fit === 'contain' ? 'meet' : 'slice') + '" clip-path="url(#' + clipId + ')"/>';
		}

		return '<svg class="vbr-balloon__svg" viewBox="0 0 24 36" aria-hidden="true">' +
			string +
			'<path d="' + BODY_PATH + '" fill="' + fill + '"/>' +
			picture +
			'<path d="' + BODY_PATH + '" fill="none" stroke="' + edge + '" stroke-width="' + (logo ? 1.4 : 1) + '"/>' +
			'<path d="M10.4 26.8 12 24.6l1.6 2.2z" fill="' + edge + '" stroke="' + edge + '" stroke-width="0.6" stroke-linejoin="round"/>' +
			'<ellipse cx="7.6" cy="8" rx="2.1" ry="3.6" transform="rotate(25 7.6 8)" fill="#fff" fill-opacity="' + (logo ? '.3' : '.45') + '"/>' +
			'</svg>';
	}

	window.vbrBalloon = {
		BURST_HEX: BURST_HEX,
		svg: balloonSvg
	};
})();
