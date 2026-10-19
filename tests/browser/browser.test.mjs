// Real-browser layout test. Run via tests/browser/run.sh (starts the PHP server and creates fixtures).
//   FIXTURES=/path/fixtures.json node browser.test.mjs
import fs from 'node:fs';
import chromium from '@sparticuz/chromium';
import puppeteer from 'puppeteer-core';
import { PNG } from 'pngjs';

const fx = JSON.parse(fs.readFileSync(process.env.FIXTURES, 'utf8'));
const SHOTS = process.env.SHOTS || '/tmp/kikz-shots';
fs.mkdirSync(SHOTS, { recursive: true });
let fails = 0, checks = 0;
const t = (name, ok, extra) => { checks++; if (!ok) fails++; console.log((ok ? '  ok   ' : '  FAIL ') + name + (ok || !extra ? '' : '  [' + extra + ']')); };

const browser = await puppeteer.launch({ executablePath: await chromium.executablePath(), args: [...chromium.args, '--no-sandbox'], headless: 'shell' });

async function open(url, width = 1300) {
	const page = await browser.newPage();
	await page.setViewport({ width, height: 1000, deviceScaleFactor: 1 });
	const errors = [];
	page.on('pageerror', (e) => errors.push(String(e)));
	await page.goto(url, { waitUntil: 'networkidle0', timeout: 60000 });
	await new Promise((r) => setTimeout(r, 700)); // let the footer script evaluate the images
	return { page, errors };
}

/** Everything we need to know about the badge of one element, measured in the real layout. */
async function inspect(page, selector) {
	return page.evaluate((sel) => {
		const el = document.querySelector(sel);
		if (!el) return null;
		const cs = getComputedStyle(el, '::after');
		const a = el.getBoundingClientRect();
		const shown = cs.content !== 'none' && cs.content !== 'normal' && cs.display !== 'none';
		const w = parseFloat(cs.width), h = parseFloat(cs.height);
		let x = null, y = null;
		if (shown) {
			const ox = a.left + el.clientLeft, oy = a.top + el.clientTop;
			x = cs.left !== 'auto' ? ox + parseFloat(cs.left) : ox + el.clientWidth - parseFloat(cs.right) - w;
			y = cs.top !== 'auto' ? oy + parseFloat(cs.top) : oy + el.clientHeight - parseFloat(cs.bottom) - h;
		}
		const img = el.tagName === 'IMG' ? el : el.querySelector('img');
		const ir = img ? img.getBoundingClientRect() : null;
		return {
			tag: el.tagName, cls: el.className, ok: el.getAttribute('data-kikz-ok'), shown, content: cs.content,
			anchor: { x: a.left + scrollX, y: a.top + scrollY, w: a.width, h: a.height },
			badge: shown ? { x: x + scrollX, y: y + scrollY, w, h } : null,
			img: ir ? { x: ir.left + scrollX, y: ir.top + scrollY, w: ir.width, h: ir.height } : null,
		};
	}, selector);
}

/** Luminance at a point of a screenshot (page coordinates). */
async function luma(page, x, y) {
	const buf = await page.screenshot({ clip: { x: Math.max(0, x), y: Math.max(0, y), width: 1, height: 1 }, captureBeyondViewport: true });
	const p = PNG.sync.read(Buffer.from(buf));
	return 0.299 * p.data[0] + 0.587 * p.data[1] + 0.114 * p.data[2];
}

const near = (a, b, tol) => Math.abs(a - b) <= tol;
const inside = (b, r, tol = 1) => b.x >= r.x - tol && b.y >= r.y - tol && b.x + b.w <= r.x + r.w + tol && b.y + b.h <= r.y + r.h + tol;

// -------------------------------------------------------------------------
async function elementorChecks(pass) {
	console.log(`\n== Elementor page, ${pass} (canvas template, real Elementor CSS) ==`);
	const { page, errors } = await open(fx.elementor);
	// NOTE: no full-page screenshot before measuring. It resizes the viewport and would trigger a
	// re-evaluation that hides exactly the bugs a real first paint has.
	const S = (id) => `[data-id="${id}"]`;
	const rect = Object.values(fx.rects)[0]; // first entry = the full-size file (the stamper writes it first)

	// A: wide strip, centered cover -> corner cropped -> overlay must show at the container's bottom-right.
	let r = await inspect(page, S('boxA0001'));
	t('A  strip (cover, centered): container is marked', r && /ki-flagged-bg/.test(r.cls) && /ki-auto/.test(r.cls), r && r.cls);
	t('A  burned-in badge is cropped -> overlay is shown', r && r.shown && r.ok === null, JSON.stringify(r && { ok: r.ok, shown: r.shown }));
	t('A  overlay sits inside the container, bottom-right, ~10px margin', r && r.badge && inside(r.badge, r.anchor) && near(r.anchor.x + r.anchor.w - (r.badge.x + r.badge.w), 10, 4) && near(r.anchor.y + r.anchor.h - (r.badge.y + r.badge.h), 10, 4), JSON.stringify(r && { b: r.badge, a: r.anchor }));

	// B: same aspect, big enough -> burned-in badge visible and legible -> overlay hidden, badge truly in the pixels.
	r = await inspect(page, S('boxB0001'));
	t('B  3:2 box, large enough: overlay is hidden (no duplicate badge)', r && !r.shown && r.ok === '1', JSON.stringify(r && { ok: r.ok, shown: r.shown }));
	if (r) {
		const px = r.anchor.x + (rect[0] + rect[2] / 2) * r.anchor.w;
		const py = r.anchor.y + (rect[1] + rect[3] * 0.1) * r.anchor.h;
		const dark = await luma(page, px, py);
		const ref = await luma(page, r.anchor.x + r.anchor.w * 0.3, r.anchor.y + r.anchor.h * 0.3);
		t('B  the burned-in badge is really visible in the pixels (dark pill where the file has it)', dark < 110, `luma=${Math.round(dark)} ref=${Math.round(ref)}`);
		t('B  and it is large enough to read (>= 15px tall on screen)', rect[3] * r.anchor.h >= 15, String(rect[3] * r.anchor.h));
	}

	// C: same crop-free box but too small -> illegible -> overlay shown.
	r = await inspect(page, S('boxC0001'));
	t('C  small box: burned-in badge would be illegible -> overlay is shown', r && r.shown && r.ok === null && r.badge && inside(r.badge, r.anchor), JSON.stringify(r && { ok: r.ok, shown: r.shown }));

	// D: tall box, centered cover -> left/right cropped.
	r = await inspect(page, S('boxD0001'));
	t('D  tall mobile-like box: overlay is shown inside the container', r && r.shown && r.badge && inside(r.badge, r.anchor), JSON.stringify(r && { shown: r.shown }));

	// E: human photo untouched.
	r = await inspect(page, S('boxE0001'));
	t('E  human photo: no label, no overlay', r && !/ki-flagged/.test(r.cls) && !r.shown, r && r.cls);

	// F: image narrower than its widget -> badge must sit at the IMAGE corner, not the widget corner.
	r = await inspect(page, S('imgF0001'));
	t('F  image widget (50% wide, centered): widget is marked', r && /ki-flagged-widget/.test(r.cls), r && r.cls);
	t('F  the image is really narrower than the widget (the tricky case)', r && r.img && r.img.w < r.anchor.w * 0.7, JSON.stringify(r && { img: r.img && r.img.w, widget: r.anchor.w }));
	t('F  overlay is shown (image downscaled -> badge illegible)', r && r.shown && r.ok === null);
	t('F  badge sits at the bottom-right of the IMAGE (not of the wide widget)', r && r.badge && r.img && near(r.img.x + r.img.w - (r.badge.x + r.badge.w), 10, 5) && near(r.img.y + r.img.h - (r.badge.y + r.badge.h), 10, 5), JSON.stringify(r && { badge: r.badge, img: r.img, widget: r.anchor }));

	// G: natural size -> burned-in badge is enough.
	r = await inspect(page, S('imgG0001'));
	t('G  image at natural size: overlay hidden, burned-in badge is the label', r && !r.shown && r.ok === '1', JSON.stringify(r && { ok: r.ok, shown: r.shown, img: r.img }));

	// H: fixed height + object-fit: cover.
	r = await inspect(page, S('imgH0001'));
	t('H  object-fit: cover (fixed height): overlay shown at the image bottom-right', r && r.shown && r.img && r.badge && inside(r.badge, r.img, 2), JSON.stringify(r && { badge: r.badge, img: r.img }));

	t('no JavaScript errors on the page', errors.length === 0, errors.join(' | '));
	await page.screenshot({ path: `${SHOTS}/elementor-${pass.split(' ')[0]}.png`, fullPage: true });
	await page.close();
}

// First visit after the fixtures cleared Elementor's cache = rendered cold; later visits come from its element cache.
await elementorChecks('cold (first visit, element rendered)');
await elementorChecks('warm (second visit, served from Elementor\'s cache)');
await elementorChecks('warm again');

console.log('\n== Classic content ==');
{
	const { page, errors } = await open(fx.classic);
	await page.screenshot({ path: `${SHOTS}/classic.png`, fullPage: true });

	let r = await inspect(page, '#scaledBox');
	t('scaled-down image (300px of a 1500px file): generic wrapper becomes the anchor, overlay shown', r && /ki-anchor/.test(r.cls) && r.shown && r.badge && inside(r.badge, r.anchor), r && r.cls);
	r = await inspect(page, '#cropBox');
	t('object-fit: cover in a square box: overlay shown inside the box', r && r.shown && r.badge && inside(r.badge, r.anchor), JSON.stringify(r && { shown: r.shown }));
	r = await inspect(page, '#humanBox');
	t('human photo: no overlay', r && !r.shown && !/ki-anchor/.test(r.cls));
	r = await inspect(page, '#wpFigure');
	t('block-editor figure: exactly one badge strategy is active', r && (r.shown !== (r.ok === '1')), JSON.stringify(r && { shown: r.shown, ok: r.ok }));
	r = await inspect(page, '#cardBox');
	t('theme card with <a><img>: anchored on the card, a badge strategy is active', r && /ki-anchor/.test(r.cls) && (r.shown !== (r.ok === '1')), JSON.stringify(r && { cls: r.cls, shown: r.shown, ok: r.ok }));
	t('no JavaScript errors on the page', errors.length === 0, errors.join(' | '));

	// Responsive: narrow the viewport and re-check that nothing breaks (ResizeObserver path).
	await page.setViewport({ width: 420, height: 900, deviceScaleFactor: 1 });
	await new Promise((r2) => setTimeout(r2, 500));
	r = await inspect(page, '#wpFigure');
	t('after resizing to a phone width the figure still shows exactly one strategy and the badge stays inside', r && (r.shown !== (r.ok === '1')) && (!r.shown || inside(r.badge, r.anchor, 2)), JSON.stringify(r && { shown: r.shown, ok: r.ok }));
	await page.screenshot({ path: `${SHOTS}/classic-mobile.png`, fullPage: true });
	await page.close();
}

await browser.close();
console.log(`\n${fails ? fails + ' FAILED of ' : 'ALL PASSED: '}${checks} checks  (screenshots: ${SHOTS})`);
process.exit(fails ? 1 : 0);
