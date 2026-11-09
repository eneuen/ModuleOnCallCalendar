// Builds public/assets/js/vendor/fullcalendar.min.js (FullCalendar Standard,
// MIT, with its LICENSE) and public/assets/js/on-call-calendar.js (the module
// page). Run: npm ci && npm run build
import { build } from 'esbuild';
import { copyFileSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const vendor = 'public/assets/js/vendor';
const page = 'public/assets/js/on-call-calendar.js';
mkdirSync(vendor, { recursive: true });

await build({
  entryPoints: ['build/fullcalendar-entry.js'],
  outfile: `${vendor}/fullcalendar.min.js`,
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2019'],
  legalComments: 'none',          // the licence text ships next to the bundle
  banner: { js: '/*! FullCalendar Standard 6.1.22 | MIT | see LICENSE.fullcalendar.txt in this folder */' },
});
copyFileSync('node_modules/@fullcalendar/core/LICENSE.md', `${vendor}/LICENSE.fullcalendar.txt`);

await build({
  entryPoints: ['public/assets/js/src/on-call-calendar.js'],
  outfile: page,
  bundle: true,
  minify: false,                  // kept readable
  format: 'iife',
  target: ['es2019'],
  banner: { js: '/* On Call Calendar for MikoPBX | GPL-3.0-or-later | built from public/assets/js/src */' },
});

for (const file of [`${vendor}/fullcalendar.min.js`, page]) {
  const bytes = statSync(file).size;
  const gz = gzipSync(readFileSync(file)).length;
  console.log(`${file}: ${(bytes / 1024).toFixed(0)} KiB, ${(gz / 1024).toFixed(0)} KiB gzip`);
}
