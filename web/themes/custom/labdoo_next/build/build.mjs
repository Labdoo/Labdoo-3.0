import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const directory = path.dirname(fileURLToPath(import.meta.url));
await build({
  entryPoints: [path.join(directory, 'chart-bar.js')],
  outfile: process.env.LABDOO_CHART_OUTPUT || path.resolve(directory, '../vendor/chart.js-4.4.0/dist/chart-bar.js'),
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2020'],
  legalComments: 'eof',
});
