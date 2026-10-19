// Run: node tests/dom.test.js   (needs: npm install)
//
// jsdom has no layout engine, so geometry (sizes, object-fit, background) is injected via stubs.
// This verifies the script's DOM behaviour: anchors, hiding/showing, late images, no observer loops.
// It does NOT replace a visual check in a real browser.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const csstree = require('css-tree');
const script = require('../assets/js/frontend.js');

let n = 0;
function t(name, fn) { try { fn(); n++; console.log('  ok   ' + name); } catch (e) { console.log('  FAIL ' + name + '\n       ' + e.message); process.exitCode = 1; } }

function tAsync(name, promise) {
	return promise.then(() => { n++; console.log('  ok   ' + name); }, (e) => { process.exitCode = 1; console.log('  FAIL ' + name + '\n       ' + e.message); });
}

const RECT = [0.84, 0.93, 0.15, 0.045]; // badge bottom-right, as stored by the stamper.

function make(html, data, stubs) {
	const dom = new JSDOM('<!doctype html><html><body>' + html + '</body></html>', { pretendToBeVisual: true, url: 'https://example.test/' });
	const w = dom.window;
	w.kikzData = data;
	let raf = 0;
	w.requestAnimationFrame = (fn) => { raf++; return w.setTimeout(fn, 0); };
	stubs && stubs(w);
	const api = script.init(w);
	return { w, doc: w.document, api, rafCount: () => raf };
}

function sizeImg(img, natW, natH, w, h, extra) {
	Object.defineProperty(img, 'naturalWidth', { value: natW, configurable: true });
	Object.defineProperty(img, 'naturalHeight', { value: natH, configurable: true });
	Object.defineProperty(img, 'complete', { value: true, configurable: true });
	Object.defineProperty(img, 'clientWidth', { value: w, configurable: true });
	Object.defineProperty(img, 'clientHeight', { value: h, configurable: true });
	Object.defineProperty(img, 'currentSrc', { value: img.src, configurable: true });
	img.getBoundingClientRect = () => Object.assign({ left: 0, top: 0, right: w, bottom: h, width: w, height: h }, extra || {});
}

function styleStub(map) {
	return (w) => {
		const orig = w.getComputedStyle.bind(w);
		w.getComputedStyle = (el) => {
			const base = orig(el);
			const extra = map(el) || {};
			return new Proxy({}, { get: (_, k) => (k in extra ? extra[k] : base[k]) });
		};
	};
}

const DATA = { rects: { 'a.jpg': RECT, 'a-300x200.jpg': [0.6, 0.78, 0.37, 0.16] }, names: { 'a.jpg': 1, 'a-300x200.jpg': 1, 'a-1024x683.jpg': 1 }, minBadge: 15 };

console.log('<img> anchors and auto-hiding');
t('figure anchor: overlay hidden when the burned-in badge is visible and legible', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" src="https://x.test/up/a.jpg"></figure>', DATA, styleStub(() => ({ objectFit: 'fill', objectPosition: '50% 50%' })));
	const img = doc.getElementById('i');
	sizeImg(img, 1200, 800, 600, 400);
	api.run();
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), '1');
});
t('object-fit: cover into a square crops the corner: overlay stays visible', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" src="https://x.test/up/a.jpg"></figure>', DATA, styleStub(() => ({ objectFit: 'cover', objectPosition: '50% 50%' })));
	sizeImg(doc.getElementById('i'), 1200, 800, 400, 400);
	api.run();
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), null);
});
t('thumbnail scale: badge too small to read -> overlay stays', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" src="https://x.test/up/a.jpg"></figure>', DATA, styleStub(() => ({ objectFit: 'fill', objectPosition: '50% 50%' })));
	sizeImg(doc.getElementById('i'), 1200, 800, 300, 200);
	api.run();
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), null);
});
t('a size-specific rect is looked up by the file actually shown (srcset/currentSrc)', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" src="https://x.test/up/a-300x200.jpg"></figure>', DATA, styleStub(() => ({ objectFit: 'fill', objectPosition: '50% 50%' })));
	sizeImg(doc.getElementById('i'), 300, 200, 300, 200);
	api.run();
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), '1'); // 0.16*200 = 32px tall.
});
t('unknown file (no rect, e.g. a WebP copy or Elementor thumb): never hidden', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" src="https://x.test/up/elementor/thumbs/a-123.jpg"></figure>', DATA, styleStub(() => ({ objectFit: 'fill' })));
	sizeImg(doc.getElementById('i'), 600, 400, 600, 400);
	api.run();
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), null);
});
t('images without ki-auto (fresh stamp / overlay mode) are never hidden', () => {
	const { doc, api } = make('<figure id="f" data-kikz-ok="1"><img id="i" class="ki-flagged-image" src="https://x.test/up/a.jpg"></figure>', DATA, styleStub(() => ({ objectFit: 'fill' })));
	sizeImg(doc.getElementById('i'), 1200, 800, 600, 400);
	api.run();
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), null);
});
t('image not loaded yet: overlay stays', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" src="https://x.test/up/a.jpg"></figure>', DATA, styleStub(() => ({ objectFit: 'fill' })));
	const img = doc.getElementById('i');
	sizeImg(img, 0, 0, 600, 400);
	Object.defineProperty(img, 'complete', { value: false, configurable: true });
	api.run();
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), null);
});

console.log('anchor offsets (image narrower than its container)');
t('Elementor image widget: offsets from container edges to the image are published as CSS variables', () => {
	const html = '<div id="w" class="elementor-widget elementor-widget-image"><div class="elementor-widget-container"><img id="i" class="ki-flagged-image" src="https://x.test/up/a.jpg"></div></div>';
	const { doc, api } = make(html, DATA);
	const w = doc.getElementById('w');
	w.getBoundingClientRect = () => ({ left: 0, top: 0, right: 800, bottom: 400, width: 800, height: 400 });
	Object.defineProperty(w, 'clientLeft', { value: 0 }); Object.defineProperty(w, 'clientTop', { value: 0 });
	Object.defineProperty(w, 'clientWidth', { value: 800 }); Object.defineProperty(w, 'clientHeight', { value: 400 });
	sizeImg(doc.getElementById('i'), 1200, 800, 500, 333, { left: 150, top: 0, right: 650, bottom: 333 });
	api.run();
	assert.strictEqual(w.style.getPropertyValue('--kikz-l'), '150px');
	assert.strictEqual(w.style.getPropertyValue('--kikz-r'), '150px');
	assert.strictEqual(w.style.getPropertyValue('--kikz-b'), '67px');
	assert.strictEqual(w.style.getPropertyValue('--kikz-t'), '0px');
});
t('generic wrapper (theme <div class="card">) becomes a positioned anchor', () => {
	const { doc, api } = make('<div id="c" class="card"><img id="i" class="ki-flagged-image" src="https://x.test/up/a.jpg"></div>', DATA);
	sizeImg(doc.getElementById('i'), 1200, 800, 600, 400);
	api.run();
	assert.ok(doc.getElementById('c').classList.contains('ki-anchor'));
});
t('<a><img></a> inside a figure: the figure stays the anchor (CSS handles it, no extra class)', () => {
	const { doc, api } = make('<figure id="f"><a href="#"><img id="i" class="ki-flagged-image" src="https://x.test/up/a.jpg"></a></figure>', DATA);
	sizeImg(doc.getElementById('i'), 1200, 800, 600, 400);
	api.run();
	assert.ok(!doc.getElementById('f').classList.contains('ki-anchor'));
});

console.log('geometry from the element itself (data-kikz-rects), no global data');
const NODATA = { minBadge: 15 };
const ATTR = JSON.stringify({ 'a.jpg': RECT, 'a-300x200.jpg': [0.6, 0.78, 0.37, 0.16], 'unstamped.jpg': 0 });
t('overlay hidden using only the element attribute (works after an HTML/Elementor cache hit)', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" data-kikz-rects=\'' + ATTR + '\' src="https://x.test/up/a.jpg"></figure>', NODATA, styleStub(() => ({ objectFit: 'fill', objectPosition: '50% 50%' })));
	sizeImg(doc.getElementById('i'), 1200, 800, 600, 400);
	api.run();
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), '1');
});
t('the attribute is parsed once per element (no repeated work on every frame)', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" data-kikz-rects=\'' + ATTR + '\' src="https://x.test/up/a.jpg"></figure>', NODATA, styleStub(() => ({ objectFit: 'fill' })));
	sizeImg(doc.getElementById('i'), 1200, 800, 600, 400);
	api.run(); api.run();
	assert.strictEqual(doc.getElementById('i').__kikzLearned, true);
});
t('a broken attribute is ignored, the overlay simply stays', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" data-kikz-rects="{not json" src="https://x.test/up/a.jpg"></figure>', NODATA, styleStub(() => ({ objectFit: 'fill' })));
	sizeImg(doc.getElementById('i'), 1200, 800, 600, 400);
	assert.doesNotThrow(() => api.run());
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), null);
});
t('a file without a badge (value 0) never counts as "badge visible"', () => {
	const { doc, api } = make('<figure id="f"><img id="i" class="ki-flagged-image ki-auto" data-kikz-rects=\'' + ATTR + '\' src="https://x.test/up/unstamped.jpg"></figure>', NODATA, styleStub(() => ({ objectFit: 'fill' })));
	sizeImg(doc.getElementById('i'), 1200, 800, 600, 400);
	api.run();
	assert.strictEqual(doc.getElementById('f').getAttribute('data-kikz-ok'), null);
});
(async () => {
	const e = make('<div id="c" class="e-con ki-flagged-bg ki-auto" data-kikz-rects=\'' + ATTR + '\'></div>', NODATA, (w) => {
		styleStub((el) => (el.id === 'c' ? { position: 'relative', backgroundImage: 'url("https://x.test/up/a.jpg")', backgroundSize: 'cover', backgroundPosition: '100% 100%', backgroundRepeat: 'no-repeat', backgroundAttachment: 'scroll' } : null))(w);
		w.Image = function () { const self = this; Object.defineProperty(this, 'src', { set() { self.naturalWidth = 1200; self.naturalHeight = 800; w.setTimeout(() => self.onload && self.onload(), 0); } }); };
	});
	const c = e.doc.getElementById('c');
	Object.defineProperty(c, 'clientWidth', { value: 900, configurable: true });
	Object.defineProperty(c, 'clientHeight', { value: 600, configurable: true });
	e.api.run();
	await new Promise((r) => e.w.setTimeout(r, 30));
	await tAsync('Elementor wrapper attribute alone is enough to hide the overlay (bottom-right cover at 3:2)', Promise.resolve().then(() => assert.strictEqual(c.getAttribute('data-kikz-ok'), '1')));
})();
const lateImages = (() => {
	const e = make('<div id="host"><img id="seed" class="ki-flagged-image" data-kikz-rects=\'' + ATTR + '\' src="https://x.test/up/a-300x200.jpg"></div>', NODATA);
	const lb = e.doc.createElement('div');
	lb.className = 'elementor-lightbox';
	lb.innerHTML = '<img id="lb1" src="https://x.test/up/a.jpg"><img id="lb2" src="https://x.test/up/unstamped.jpg"><img id="lb3" src="https://x.test/up/other.jpg">';
	e.doc.getElementById('host').appendChild(lb);
	return new Promise((resolve, reject) => e.w.setTimeout(() => {
		try {
			const c = (id) => e.doc.getElementById(id).classList;
			assert.ok(c('lb1').contains('ki-flagged-image') && c('lb1').contains('ki-auto'), 'stamped file adopted with ki-auto');
			assert.ok(c('lb2').contains('ki-flagged-image') && !c('lb2').contains('ki-auto'), 'unstamped file adopted WITHOUT ki-auto');
			assert.ok(!c('lb3').contains('ki-flagged-image'), 'unrelated file left alone');
			resolve();
		} catch (err) { reject(err); }
	}, 40));
})();
tAsync('file names for late images (lightbox) are learned from attributes already in the page', lateImages);

console.log('CSS backgrounds (Elementor containers)');
function bgEnv(styles, natural) {
	return make('<div id="c" class="e-con ki-flagged-bg ki-auto"></div>', DATA, (w) => {
		styleStub((el) => (el.id === 'c' ? styles : null))(w);
		w.Image = function () { const self = this; Object.defineProperty(this, 'src', { set() { self.naturalWidth = natural[0]; self.naturalHeight = natural[1]; w.setTimeout(() => self.onload && self.onload(), 0); } }); };
	});
}
function sizeBox(el, w, h) {
	Object.defineProperty(el, 'clientWidth', { value: w, configurable: true });
	Object.defineProperty(el, 'clientHeight', { value: h, configurable: true });
}
const wait = (w) => new Promise((r) => w.setTimeout(r, 30));

(async () => {
	await (async () => {
		const e = bgEnv({ position: 'relative', backgroundImage: 'linear-gradient(rgba(0,0,0,.4),rgba(0,0,0,.4)), url("https://x.test/up/a.jpg")', backgroundSize: 'auto, cover', backgroundPosition: '0% 0%, 100% 100%', backgroundRepeat: 'repeat, no-repeat', backgroundAttachment: 'scroll, scroll' }, [1200, 800]);
		const c = e.doc.getElementById('c');
		sizeBox(c, 400, 700);
		e.api.run();
		await wait(e.w);
		t('multi-layer background: picks the image layer (not the gradient) and honours its own position -> bottom-right cover is visible', () => assert.strictEqual(c.getAttribute('data-kikz-ok'), '1'));
	})();
	await (async () => {
		const e = bgEnv({ position: 'relative', backgroundImage: 'url("https://x.test/up/a.jpg")', backgroundSize: 'cover', backgroundPosition: '50% 50%', backgroundRepeat: 'no-repeat', backgroundAttachment: 'scroll' }, [1200, 800]);
		const c = e.doc.getElementById('c');
		sizeBox(c, 400, 700);
		e.api.run();
		await wait(e.w);
		t('centered cover on a tall mobile container: corner cropped -> overlay stays', () => assert.strictEqual(c.getAttribute('data-kikz-ok'), null));
	})();
	await (async () => {
		const e = bgEnv({ position: 'relative', backgroundImage: 'url("https://x.test/up/a.jpg")', backgroundSize: 'cover', backgroundPosition: '50% 50%', backgroundRepeat: 'no-repeat', backgroundAttachment: 'fixed' }, [1200, 800]);
		const c = e.doc.getElementById('c');
		sizeBox(c, 1200, 800);
		e.api.run();
		await wait(e.w);
		t('parallax (fixed attachment) is never trusted', () => assert.strictEqual(c.getAttribute('data-kikz-ok'), null));
	})();
	await (async () => {
		const e = bgEnv({ position: 'static', backgroundImage: 'none' }, [1, 1]);
		const c = e.doc.getElementById('c');
		e.api.run();
		t('a static-positioned background element gets positioned so ::after anchors to it', () => assert.ok(c.classList.contains('ki-anchor')));
		t('not-yet-loaded (lazy) background: overlay stays', () => assert.strictEqual(c.getAttribute('data-kikz-ok'), null));
	})();

	console.log('images that appear later (lightbox, sliders)');
	await (async () => {
		const e = make('<div id="host"></div>', DATA);
		const lb = e.doc.createElement('div');
		lb.className = 'elementor-lightbox';
		lb.innerHTML = '<img id="lb" src="https://x.test/up/a-1024x683.jpg">';
		e.doc.getElementById('host').appendChild(lb);
		await wait(e.w);
		const img = e.doc.getElementById('lb');
		t('lightbox image of a flagged attachment is adopted (overlay class added)', () => assert.ok(img.classList.contains('ki-flagged-image')));
		t('size without a stored rect is adopted WITHOUT ki-auto (so it can never be hidden)', () => assert.ok(!img.classList.contains('ki-auto')));
		const other = e.doc.createElement('img');
		other.src = 'https://x.test/up/unrelated.jpg';
		lb.appendChild(other);
		await wait(e.w);
		t('unrelated images are left alone', () => assert.ok(!other.classList.contains('ki-flagged-image')));
		const known = e.doc.createElement('img');
		known.src = 'https://x.test/up/a.jpg';
		e.doc.getElementById('host').appendChild(known);
		await wait(e.w);
		t('a late <img> of a stamped size gets ki-auto', () => assert.ok(known.classList.contains('ki-flagged-image') && known.classList.contains('ki-auto')));
	})();

	console.log('stability');
	await (async () => {
		const e = bgEnv({ position: 'static', backgroundImage: 'none' }, [1, 1]);
		const c = e.doc.getElementById('c');
		e.api.run();
		await wait(e.w);
		const settled = e.rafCount();
		await wait(e.w); await wait(e.w); await wait(e.w);
		t('no observer feedback loop: no further frames are scheduled once settled', () => assert.strictEqual(e.rafCount(), settled, `frames grew from ${settled} to ${e.rafCount()}`));
		for (let i = 0; i < 5; i++) { c.setAttribute('style', 'color:red;' + i); }
		await wait(e.w);
		const after = e.rafCount();
		await wait(e.w); await wait(e.w);
		t('style changes on a watched element cause a bounded number of re-evaluations', () => assert.ok(e.rafCount() - settled <= 3 && e.rafCount() === after, `frames ${settled} -> ${e.rafCount()}`));
	})();

	console.log('CSS emitted by the plugin parses');
	// The CSS depends on the options, so render it once per position through a tiny PHP harness.
	const harness = `
		define('ABSPATH','/'); define('KIKZ_VERSION','2'); define('KIKZ_URL','/'); define('KIKZ_DIR','/'); define('KIKZ_BASENAME','x'); define('DAY_IN_SECONDS',86400);
		$GLOBALS['o']=[];
		function get_option($k,$d=false){ return $GLOBALS['o'][$k] ?? $d; }
		function wp_strip_all_tags($s){return strip_tags($s);} function remove_accents($s){return $s;} function sanitize_key($s){return $s;}
		function add_action(){} function add_filter(){} function add_shortcode(){} function plugin_basename($f){return $f;}
		class KI_Kennzeichnung_Flags { static function noop(){} }
		require '${path.resolve(__dirname, '../includes/class-kikz-settings.php')}';
		require '${path.resolve(__dirname, '../includes/class-kikz-frontend.php')}';
		$out=[];
		foreach (KI_Kennzeichnung_Settings::POSITIONS as $p) { $GLOBALS['o']=['kikz_badge_corner'=>$p,'kikz_badge_margin'=>'14','kikz_badge_text'=>'KI "x" & Co']; ob_start(); KI_Kennzeichnung_Frontend::print_styles(); $out[$p]=ob_get_clean(); }
		echo json_encode($out);`;
	const res = require('child_process').spawnSync('php', ['-r', harness], { encoding: 'utf8' });
	const byPos = JSON.parse(res.stdout || '{}');
	t('rendered CSS for all 9 positions', () => assert.strictEqual(Object.keys(byPos).length, 9, res.stderr));
	Object.keys(byPos).forEach((pos) => {
		t('CSS parses without errors: ' + pos, () => {
			const css = byPos[pos].replace(/^<style[^>]*>/, '').replace(/<\/style>\s*$/, '');
			const errors = [];
			csstree.parse(css, { onParseError: (err) => errors.push(err.message) });
			assert.deepStrictEqual(errors, []);
			assert.ok(/--kikz-label:"KI \\"x\\" & Co"/.test(css), 'label escaped');
			assert.ok(css.includes('::after'), 'has the badge rule');
		});
	});
	t('bottom-right anchors to the image box via the inset variables', () => assert.ok(/right:calc\(var\(--kikz-r\) \+ 14px\)/.test(byPos['bottom-right']) && /bottom:calc\(var\(--kikz-b\) \+ 14px\)/.test(byPos['bottom-right'])));

	console.log(process.exitCode ? '\nFAILED' : '\nALL PASSED (' + n + ')');
})();
