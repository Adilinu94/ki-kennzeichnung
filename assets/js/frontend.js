/*!
 * KI-Kennzeichnung – front-end refinement.
 *
 * The overlay badge works with CSS alone. This script only
 *  1. anchors the badge to the visible image box (not the wider container),
 *  2. hides the overlay where the badge burned into the file is verifiably
 *     visible AND legible (so there is never a duplicate), and keeps it when the
 *     burned-in badge is cropped (object-fit / background-size: cover) or too small,
 *  3. labels images that are injected later (lightbox, sliders, variation swap).
 *
 * If this script never runs (blocked, delayed by a performance plugin) the CSS
 * overlay simply stays visible: the image is still labeled.
 */
(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	}
	if (typeof window !== 'undefined' && window.document) {
		api.boot(window);
	}
})(this, function () {
	'use strict';

	var TOL = 0.75; // px tolerance for "inside the box".

	// ------------------------------------------------------------------
	// Geometry (pure functions, unit-tested in tests/geometry.test.js).
	// ------------------------------------------------------------------

	/** Resolve a position token ({t:'%'|'px', v}) against free space. */
	function posOffset(tok, free) {
		if (!tok) { return free * 0.5; }
		return tok.t === '%' ? free * tok.v : tok.v;
	}

	/** Rect of the drawn image inside its box for an object-fit value. */
	function drawnRect(nw, nh, bw, bh, fit, px, py) {
		var s, w, h;
		if (!(nw > 0 && nh > 0 && bw > 0 && bh > 0)) { return null; }
		switch (fit || 'fill') {
			case 'fill': w = bw; h = bh; break;
			case 'contain': s = Math.min(bw / nw, bh / nh); w = nw * s; h = nh * s; break;
			case 'cover': s = Math.max(bw / nw, bh / nh); w = nw * s; h = nh * s; break;
			case 'none': w = nw; h = nh; break;
			case 'scale-down': s = Math.min(1, Math.min(bw / nw, bh / nh)); w = nw * s; h = nh * s; break;
			default: return null;
		}
		return { x: posOffset(px, bw - w), y: posOffset(py, bh - h), w: w, h: h };
	}

	/** Is the burned-in badge fully inside the box and tall enough to read? */
	function badgeVisible(d, rect, box, minH) {
		if (!d || !rect) { return false; }
		var bx = d.x + rect[0] * d.w, by = d.y + rect[1] * d.h;
		var bw = rect[2] * d.w, bh = rect[3] * d.h;
		return bx >= -TOL && by >= -TOL && bx + bw <= box.w + TOL && by + bh <= box.h + TOL && bh >= minH;
	}

	/** Parse "50% 50%" / "10px 20px" (computed object/background-position). */
	function parsePos(str) {
		var parts = String(str || '').trim().split(/\s+/), out = [], i, m;
		if (parts.length !== 2) { return null; }
		for (i = 0; i < 2; i++) {
			m = /^(-?\d*\.?\d+)(%|px)$/.exec(parts[i]);
			if (!m) { return null; }
			out.push({ t: m[2], v: m[2] === '%' ? parseFloat(m[1]) / 100 : parseFloat(m[1]) });
		}
		return out;
	}

	/** Rect of a CSS background image layer, or null when it cannot be told safely. */
	function bgDrawn(nw, nh, bw, bh, size, pos, repeat, attachment) {
		var w, h, s, t, m, toks, p;
		if (attachment && attachment.indexOf('fixed') !== -1) { return null; }
		size = String(size || 'auto').trim();
		if (size === 'cover') { s = Math.max(bw / nw, bh / nh); w = nw * s; h = nh * s; }
		else if (size === 'contain') { s = Math.min(bw / nw, bh / nh); w = nw * s; h = nh * s; }
		else {
			toks = size.split(/\s+/);
			if (toks.length === 1) { toks.push('auto'); }
			var dim = function (tok, full) {
				if (tok === 'auto') { return null; }
				m = /^(-?\d*\.?\d+)(%|px)$/.exec(tok);
				if (!m) { return undefined; }
				return m[2] === '%' ? full * parseFloat(m[1]) / 100 : parseFloat(m[1]);
			};
			w = dim(toks[0], bw); h = dim(toks[1], bh);
			if (w === undefined || h === undefined) { return null; }
			if (w === null && h === null) { w = nw; h = nh; }
			else if (w === null) { w = h * nw / nh; }
			else if (h === null) { h = w * nh / nw; }
		}
		// Tiled backgrounds: only safe when a single, larger-than-box tile covers everything.
		if (repeat && repeat.indexOf('no-repeat') === -1 && !(w >= bw && h >= bh)) { return null; }
		p = parsePos(pos);
		if (!p) { return null; }
		t = { x: posOffset(p[0], bw - w), y: posOffset(p[1], bh - h), w: w, h: h };
		return t;
	}

	/** Split a computed multi-value property on top-level commas. */
	function splitTop(str) {
		var out = [], depth = 0, cur = '', i, c;
		for (i = 0; i < str.length; i++) {
			c = str.charAt(i);
			if (c === '(') { depth++; }
			if (c === ')') { depth--; }
			if (c === ',' && depth === 0) { out.push(cur.trim()); cur = ''; } else { cur += c; }
		}
		if (cur.trim() !== '') { out.push(cur.trim()); }
		return out;
	}

	function basename(url) {
		var u = String(url || '').split('#')[0].split('?')[0];
		try { u = decodeURIComponent(u); } catch (e) { /* keep raw */ }
		return u.substring(u.lastIndexOf('/') + 1);
	}

	// ------------------------------------------------------------------
	// DOM part.
	// ------------------------------------------------------------------

	function boot(win) {
		var doc = win.document;
		var D = win.kikzData || { rects: {}, names: {}, minBadge: 15 };
		var rects = D.rects || {};
		var names = D.names || {};
		var minBadge = D.minBadge || 15;
		var scheduled = false;
		var bgImages = {};

		/**
		 * Badge geometry travels on the element itself (data-kikz-rects): name -> [x,y,w,h] or 0.
		 * That survives Elementor's element cache and any HTML cache, which hand out markup without
		 * running the plugin's PHP again.
		 */
		function learn(el) {
			if (!el || el.__kikzLearned || !el.getAttribute) { return; }
			var raw = el.getAttribute('data-kikz-rects');
			if (!raw) { return; }
			el.__kikzLearned = true;
			var map, id = el.getAttribute('data-kikz-id') || 1;
			try { map = JSON.parse(raw); } catch (e) { return; }
			Object.keys(map).forEach(function (name) {
				names[name] = id;
				if (map[name] && map[name].length === 4) { rects[name] = map[name]; }
			});
		}

		function setVars(anchor, l, t, r, b) {
			var st = anchor.style;
			st.setProperty('--kikz-l', Math.max(0, Math.round(l)) + 'px');
			st.setProperty('--kikz-t', Math.max(0, Math.round(t)) + 'px');
			st.setProperty('--kikz-r', Math.max(0, Math.round(r)) + 'px');
			st.setProperty('--kikz-b', Math.max(0, Math.round(b)) + 'px');
		}

		/** Anchor element for the badge of an <img>. */
		function anchorFor(img) {
			var known = img.closest('.elementor-widget-image, .elementor-image-box-img, .ki-flagged-widget');
			if (known) { return known; }
			var link = img.closest('a');
			var cand = (link && link.contains(img) && link.parentElement) ? link.parentElement : img.parentElement;
			if (!cand) { return null; }
			if ((!cand.matches('figure, .gallery-icon, .wp-block-cover') || cand.closest('.elementor-widget')) && !cand.classList.contains('ki-anchor')) {
				cand.classList.add('ki-anchor');
			}
			return cand;
		}

		/** Offsets of the image inside its anchor's padding box. */
		function insets(anchor, img) {
			var a = anchor.getBoundingClientRect(), i = img.getBoundingClientRect();
			var l = i.left - a.left - anchor.clientLeft;
			var t = i.top - a.top - anchor.clientTop;
			var r = (a.left + anchor.clientLeft + anchor.clientWidth) - i.right;
			var b = (a.top + anchor.clientTop + anchor.clientHeight) - i.bottom;
			setVars(anchor, l, t, r, b);
		}

		function evalImg(img) {
			learn(img);
			var anchor = anchorFor(img);
			if (!anchor) { return; }
			insets(anchor, img);
			if (!img.classList.contains('ki-auto')) { anchor.removeAttribute('data-kikz-ok'); return; }
			var rect = rects[basename(img.currentSrc || img.src)];
			if (!rect || !img.complete || !img.naturalWidth) { anchor.removeAttribute('data-kikz-ok'); return; }
			var cs = win.getComputedStyle(img);
			var box = { w: img.clientWidth, h: img.clientHeight };
			var pos = parsePos(cs.objectPosition) || [{ t: '%', v: 0.5 }, { t: '%', v: 0.5 }];
			var d = drawnRect(img.naturalWidth, img.naturalHeight, box.w, box.h, cs.objectFit, pos[0], pos[1]);
			if (d && badgeVisible(d, rect, box, minBadge)) { anchor.setAttribute('data-kikz-ok', '1'); }
			else { anchor.removeAttribute('data-kikz-ok'); }
		}

		function evalBg(el) {
			learn(el);
			var cs = win.getComputedStyle(el);
			if (cs.position === 'static' && !el.classList.contains('ki-anchor')) { el.classList.add('ki-anchor'); }
			if (!el.classList.contains('ki-auto')) { return; }
			var layers = splitTop(cs.backgroundImage), i, m, url = '', idx = -1;
			for (i = 0; i < layers.length; i++) {
				m = /url\(\s*["']?([^"')]+)/.exec(layers[i]);
				if (m && rects[basename(m[1])]) { idx = i; url = m[1]; break; }
			}
			if (idx < 0) { el.removeAttribute('data-kikz-ok'); return; }
			var pick = function (list) { return list[idx % list.length] || list[0]; };
			var size = pick(splitTop(cs.backgroundSize)), pos = pick(splitTop(cs.backgroundPosition));
			var rep = pick(splitTop(cs.backgroundRepeat)), att = pick(splitTop(cs.backgroundAttachment));
			var apply = function (nw, nh) {
				var box = { w: el.clientWidth, h: el.clientHeight };
				var d = bgDrawn(nw, nh, box.w, box.h, size, pos, rep, att);
				if (d && badgeVisible(d, rects[basename(url)], box, minBadge)) { el.setAttribute('data-kikz-ok', '1'); }
				else { el.removeAttribute('data-kikz-ok'); }
			};
			if (bgImages[url] && bgImages[url].w) { apply(bgImages[url].w, bgImages[url].h); return; }
			var im = new win.Image();
			im.onload = function () { bgImages[url] = { w: im.naturalWidth, h: im.naturalHeight }; apply(im.naturalWidth, im.naturalHeight); };
			im.src = url;
		}

		function run() {
			scheduled = false;
			var i, list = doc.querySelectorAll('img.ki-flagged-image'), bgs = doc.querySelectorAll('.ki-flagged-bg'), withData = doc.querySelectorAll('[data-kikz-rects]');
			for (i = 0; i < withData.length; i++) { learn(withData[i]); } // also teaches the file names for late images (lightbox).
			for (i = 0; i < list.length; i++) { evalImg(list[i]); }
			for (i = 0; i < bgs.length; i++) { evalBg(bgs[i]); }
		}

		function schedule() {
			if (scheduled) { return; }
			scheduled = true;
			(win.requestAnimationFrame || win.setTimeout)(run, 16);
		}

		/** Label images that appear later (lightbox, sliders). */
		function adopt(img) {
			if (!img || img.nodeType !== 1 || img.tagName !== 'IMG' || img.classList.contains('ki-flagged-image')) { return; }
			learn(img);
			var name = basename(img.currentSrc || img.src);
			if (!names[name]) { return; }
			img.classList.add('ki-flagged-image');
			if (rects[name]) { img.classList.add('ki-auto'); }
			schedule();
		}

		function onMutations(list) {
			var i, j, n, nodes;
			for (i = 0; i < list.length; i++) {
				nodes = list[i].addedNodes;
				for (j = 0; j < nodes.length; j++) {
					n = nodes[j];
					if (n.nodeType !== 1) { continue; }
					if (n.tagName === 'IMG') { adopt(n); }
					else if (n.querySelectorAll && /lightbox|pswp|mfp|fancybox|slide|swiper|modal|popup|zoom/i.test(String(n.className))) {
						var imgs = n.querySelectorAll('img'), k;
						for (k = 0; k < imgs.length; k++) { adopt(imgs[k]); }
					}
				}
			}
		}

		function woo() {
			var $ = win.jQuery;
			if (!$) { return; }
			$(doc).on('found_variation', 'form.variations_form', function (e, v) {
				var img = doc.querySelector('.woocommerce-product-gallery__image img');
				if (!img) { return; }
				if (img.getAttribute('data-kikz-orig') === null) { img.setAttribute('data-kikz-orig', img.className); }
				img.classList.remove('ki-flagged-image', 'ki-auto');
				if (v && v.kikz_flagged) { String(v.kikz_classes || 'ki-flagged-image').split(' ').forEach(function (c) { if (c) { img.classList.add(c); } }); }
				if (img.complete) { schedule(); }
			});
			$(doc).on('reset_image', 'form.variations_form', function () {
				var img = doc.querySelector('.woocommerce-product-gallery__image img');
				if (img && img.getAttribute('data-kikz-orig') !== null) { img.className = img.getAttribute('data-kikz-orig'); schedule(); }
			});
		}

		doc.addEventListener('load', function (e) {
			if (e.target && e.target.tagName === 'IMG' && e.target.classList.contains('ki-flagged-image')) { schedule(); }
		}, true);
		win.addEventListener('resize', schedule);
		win.addEventListener('load', schedule);
		if (win.ResizeObserver) {
			var ro = new win.ResizeObserver(schedule);
			var watch = doc.querySelectorAll('img.ki-flagged-image, .ki-flagged-bg'), w;
			for (w = 0; w < watch.length; w++) { ro.observe(watch[w]); }
		}
		if (win.MutationObserver && doc.body) {
			new win.MutationObserver(onMutations).observe(doc.body, { childList: true, subtree: true });
			// Elementor lazy-loads CSS backgrounds by toggling classes on the element itself.
			var bgWatch = new win.MutationObserver(schedule), bgs = doc.querySelectorAll('.ki-flagged-bg'), b;
			for (b = 0; b < bgs.length; b++) { bgWatch.observe(bgs[b], { attributes: true, attributeFilter: ['class', 'style'] }); }
		}
		woo();
		run();
		return { run: run };
	}

	return {
		drawnRect: drawnRect,
		badgeVisible: badgeVisible,
		bgDrawn: bgDrawn,
		parsePos: parsePos,
		splitTop: splitTop,
		basename: basename,
		init: boot, // Synchronous initialisation (used by the tests).
		boot: function (win) {
			var go = function () { win.kikzRun = boot(win).run; };
			if (win.document.readyState === 'loading') { win.document.addEventListener('DOMContentLoaded', go); } else { go(); }
		}
	};
});
