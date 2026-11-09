// Builds public/assets/js/vendor/fullcalendar.min.js and
// public/assets/css/vendor/fullcalendar.min.css (FullCalendar 7 with the Pulse
// theme, MIT, with its LICENSE) and public/assets/js/on-call-calendar.js (the
// module page). Run: npm ci && npm run build
import { build } from 'esbuild';
import { copyFileSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const vendor = 'public/assets/js/vendor';
const vendorCss = 'public/assets/css/vendor';
const page = 'public/assets/js/on-call-calendar.js';
mkdirSync(vendor, { recursive: true });
mkdirSync(vendorCss, { recursive: true });
const version = JSON.parse(readFileSync('node_modules/fullcalendar/package.json', 'utf8')).version;

await build({
  entryPoints: ['build/fullcalendar-entry.js'],
  outfile: `${vendor}/fullcalendar.min.js`,
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2019'],
  legalComments: 'none',          // the licence text ships next to the bundle
  banner: { js: `/*! FullCalendar ${version} with the Pulse theme | MIT | see LICENSE.fullcalendar.txt in this folder */` },
});
copyFileSync('node_modules/fullcalendar/LICENSE.md', `${vendor}/LICENSE.fullcalendar.txt`);

await build({
  entryPoints: ['build/fullcalendar.css'],
  outfile: `${vendorCss}/fullcalendar.min.css`,
  bundle: true,
  minify: true,
  legalComments: 'none',
  banner: { css: `/*! FullCalendar ${version}, Pulse theme | MIT | see js/vendor/LICENSE.fullcalendar.txt */` },
});

await build({
  entryPoints: ['public/assets/js/src/on-call-calendar.js'],
  outfile: page,
  bundle: true,
  minify: false,                  // kept readable
  format: 'iife',
  target: ['es2019'],
  banner: { js: '/* On Call Calendar for MikoPBX | GPL-3.0-or-later | built from public/assets/js/src */' },
});

for (const file of [`${vendor}/fullcalendar.min.js`, `${vendorCss}/fullcalendar.min.css`, page]) {
  const bytes = statSync(file).size;
  const gz = gzipSync(readFileSync(file)).length;
  console.log(`${file}: ${(bytes / 1024).toFixed(0)} KiB, ${(gz / 1024).toFixed(0)} KiB gzip`);
}
