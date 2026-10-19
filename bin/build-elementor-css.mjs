// Compiles the stylesheets a git checkout of Elementor lacks (it ships only the SCSS sources).
//   node bin/build-elementor-css.mjs <elementor-plugin-dir> <path-to-sass-module>
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const [, , dir, sassPath] = process.argv;
const sass = createRequire(import.meta.url)(sassPath);
process.chdir(dir);
fs.mkdirSync('assets/css', { recursive: true });

const front = sass.compile('assets/dev/scss/direction/frontend.scss', { style: 'compressed', logger: sass.Logger.silent });
fs.writeFileSync('assets/css/frontend.min.css', front.css);
fs.writeFileSync('assets/css/frontend.css', front.css);

let widgets = 0;
const wdir = 'assets/dev/scss/frontend/widgets';
for (const f of fs.readdirSync(wdir)) {
	if (!f.endsWith('.scss')) continue;
	const name = f.replace(/\.scss$/, '').replace(/^_/, '').replace(/_/g, '-');
	try {
		const r = sass.compileString(`$direction: ltr;\n@import "${path.resolve(wdir, f).replace(/\\/g, '/')}";`, {
			style: 'compressed', logger: sass.Logger.silent,
			loadPaths: [path.resolve(wdir), path.resolve('assets/dev/scss/frontend'), path.resolve('assets/dev/scss')],
		});
		fs.writeFileSync(`assets/css/widget-${name}.min.css`, r.css);
		widgets++;
	} catch (e) { /* some widget sheets need generated imports; the image widget (used by the tests) does not */ }
}
console.log(`frontend.min.css ${front.css.length} bytes, ${widgets} widget stylesheets`);
if (!fs.existsSync('assets/css/widget-image.min.css')) { console.error('widget-image.min.css was not built'); process.exit(1); }
