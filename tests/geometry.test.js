// Run: node tests/geometry.test.js
const assert = require('assert');
const g = require('../assets/js/frontend.js');

let n = 0;
function t(name, fn) { try { fn(); n++; console.log('  ok   ' + name); } catch (e) { console.log('  FAIL ' + name + '\n       ' + e.message); process.exitCode = 1; } }

// Badge in the bottom-right corner of a 1200x800 file, ~4.5% of the height.
const R = [0.84, 0.93, 0.15, 0.045];
const MIN = 15;
const center = [{ t: '%', v: 0.5 }, { t: '%', v: 0.5 }];

console.log('<img> / object-fit');
t('fill, 600x400: badge visible and 18px tall', () => {
	const box = { w: 600, h: 400 };
	assert.ok(g.badgeVisible(g.drawnRect(1200, 800, 600, 400, 'fill', ...center), R, box, MIN));
});
t('fill, 300x200 thumbnail from the big file: badge only 9px tall -> overlay needed', () => {
	const box = { w: 300, h: 200 };
	assert.ok(!g.badgeVisible(g.drawnRect(1200, 800, 300, 200, 'fill', ...center), R, box, MIN));
});
t('cover into a square: badge cropped away -> overlay needed', () => {
	const box = { w: 400, h: 400 };
	assert.ok(!g.badgeVisible(g.drawnRect(1200, 800, 400, 400, 'cover', ...center), R, box, MIN));
});
t('cover into a wide strip: badge cropped vertically -> overlay needed', () => {
	const box = { w: 900, h: 200 };
	assert.ok(!g.badgeVisible(g.drawnRect(1200, 800, 900, 200, 'cover', ...center), R, box, MIN));
});
t('cover with object-position bottom right keeps the corner visible', () => {
	const box = { w: 400, h: 400 };
	const d = g.drawnRect(1200, 800, 400, 400, 'cover', { t: '%', v: 1 }, { t: '%', v: 1 });
	assert.ok(g.badgeVisible(d, R, box, MIN));
});
t('contain letterbox: whole image visible', () => {
	const box = { w: 500, h: 500 };
	assert.ok(g.badgeVisible(g.drawnRect(1200, 800, 500, 500, 'contain', ...center), R, box, 6));
});
t('none: image larger than the box is cropped', () => {
	const box = { w: 400, h: 300 };
	assert.ok(!g.badgeVisible(g.drawnRect(1200, 800, 400, 300, 'none', ...center), R, box, MIN));
});
t('unknown object-fit is treated as unsafe', () => {
	assert.strictEqual(g.drawnRect(1200, 800, 400, 300, 'weird', ...center), null);
	assert.ok(!g.badgeVisible(null, R, { w: 1, h: 1 }, MIN));
});
t('degenerate sizes do not throw', () => {
	assert.strictEqual(g.drawnRect(0, 0, 100, 100, 'cover', ...center), null);
});

console.log('CSS backgrounds (Elementor containers)');
t('cover on a desktop container (wide): corner cropped at 100% 50%? no - centered, vertical crop only', () => {
	const box = { w: 1200, h: 500 };
	const d = g.bgDrawn(1200, 800, 1200, 500, 'cover', '50% 50%', 'no-repeat', 'scroll');
	assert.ok(!g.badgeVisible(d, R, box, MIN)); // bottom is cut off
});
t('cover, anchored at bottom right: visible', () => {
	const box = { w: 400, h: 700 };
	const d = g.bgDrawn(1200, 800, 400, 700, 'cover', '100% 100%', 'no-repeat', 'scroll');
	assert.ok(g.badgeVisible(d, R, box, MIN));
});
t('cover, centered on a tall mobile container: corner cropped', () => {
	const box = { w: 400, h: 700 };
	const d = g.bgDrawn(1200, 800, 400, 700, 'cover', '50% 50%', 'no-repeat', 'scroll');
	assert.ok(!g.badgeVisible(d, R, box, MIN));
});
t('fixed attachment (parallax) is never trusted', () => {
	assert.strictEqual(g.bgDrawn(1200, 800, 400, 700, 'cover', '50% 50%', 'no-repeat', 'fixed'), null);
});
t('repeating small tile is never trusted', () => {
	assert.strictEqual(g.bgDrawn(200, 200, 800, 600, 'auto', '0% 0%', 'repeat', 'scroll'), null);
});
t('auto size, no-repeat, image bigger than box -> cropped', () => {
	const box = { w: 600, h: 400 };
	const d = g.bgDrawn(1200, 800, 600, 400, 'auto', '0% 0%', 'no-repeat', 'scroll');
	assert.ok(!g.badgeVisible(d, R, box, MIN));
});
t('100% auto (width fitted)', () => {
	const box = { w: 1200, h: 900 };
	const d = g.bgDrawn(1200, 800, 1200, 900, '100% auto', '0% 100%', 'no-repeat', 'scroll');
	assert.ok(d && Math.abs(d.w - 1200) < 1e-6 && Math.abs(d.h - 800) < 1e-6);
	assert.ok(g.badgeVisible(d, R, box, MIN));
});
t('calc() positions are not parsed -> unsafe', () => {
	assert.strictEqual(g.bgDrawn(1200, 800, 400, 700, 'cover', 'calc(100% - 20px) 50%', 'no-repeat', 'scroll'), null);
});

console.log('helpers');
t('parsePos', () => {
	assert.deepStrictEqual(g.parsePos('50% 50%'), [{ t: '%', v: 0.5 }, { t: '%', v: 0.5 }]);
	assert.deepStrictEqual(g.parsePos('10px 20px'), [{ t: 'px', v: 10 }, { t: 'px', v: 20 }]);
	assert.strictEqual(g.parsePos('left top'), null);
});
t('splitTop keeps gradients in one piece', () => {
	assert.deepStrictEqual(g.splitTop('url("a.jpg"), linear-gradient(rgba(0,0,0,.5), rgba(0,0,0,.5))'), ['url("a.jpg")', 'linear-gradient(rgba(0,0,0,.5), rgba(0,0,0,.5))']);
});
t('basename handles query strings and encoded names', () => {
	assert.strictEqual(g.basename('https://x.test/wp-content/uploads/2025/10/my%20pic-300x200.jpg?ver=2#x'), 'my pic-300x200.jpg');
});

console.log(process.exitCode ? '\nFAILED' : '\nALL PASSED (' + n + ')');
