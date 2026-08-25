/**
 * Fails if the TypeScript fallback drifts from the PHP one.
 *
 * save() writes the TS version into post content and PHP validates and renders
 * from the same attributes, so the two must agree exactly. Run with:
 *   node tests/fallback-parity.mjs
 */
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, rmSync } from 'node:fs';

import path from 'node:path';

const dir = path.dirname(new URL(import.meta.url).pathname);
// esbuild lives in admin/node_modules, which is where the editor bundle builds.
const { build } = await import(path.join(dir, '../admin/node_modules/esbuild/lib/main.js'));
const tmp = path.join(dir, '.fallback.build.mjs');

await build({
  entryPoints: [path.join(dir, '../admin/src/editor/fallback.ts')],
  outfile: tmp, format: 'esm', bundle: true, platform: 'neutral', logLevel: 'silent',
});
const { visualFallback, ctaFallback } = await import(tmp + '?t=' + Date.now());
rmSync(tmp);

const fixtures = JSON.parse(readFileSync(path.join(dir, 'fixtures.json'), 'utf8'));
const phpOut = JSON.parse(execFileSync('php', [path.join(dir, 'fallback-parity.php')], { encoding: 'utf8' }));

let fail = 0;
fixtures.forEach((f, i) => {
  const js = f.kind === 'cta' ? ctaFallback(f.attrs) : visualFallback(f.attrs);
  const php = phpOut[i];
  if (js !== php) {
    fail++;
    console.log(`FAIL #${i} (${f.kind})\n  php: ${JSON.stringify(php)}\n  js : ${JSON.stringify(js)}`);
  } else {
    console.log(`ok   #${i} ${f.kind}  ${JSON.stringify(php).slice(0, 70)}`);
  }
});
console.log(fail === 0 ? `\nPARITY OK (${fixtures.length} fixtures)` : `\n${fail} MISMATCHES`);
process.exit(fail === 0 ? 0 : 1);
