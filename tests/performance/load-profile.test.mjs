import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createHash } from 'node:crypto';

// Evaluate the real scenario with local HTTP/k6 adapters; never generate load.
const source = fs.readFileSync(new URL('./labdoo-load.js', import.meta.url), 'utf8')
  .replace(/^import .*;$/gm, '')
  .replace('export const options', 'const options')
  .replace('export function setup', 'function setup')
  .replace('export default function ()', 'function iteration()');
function scenario(environment, response) {
  const checks = [];
  const http = { get: () => response };
  const check = (value, assertions) => checks.push(...Object.entries(assertions).map(([name, predicate]) => ({ name, passed: predicate(value) })));
  const crypto = { sha256: value => createHash('sha256').update(value).digest('hex') };
  const build = new Function('__ENV', 'http', 'check', 'sleep', 'encoding', 'crypto', source + '\nreturn {options, iteration, setup};');
  return { ...build(environment, http, check, () => {}, {}, crypto), checks };
}
const root = '/checkout/under-test';
const environment = { LABDOO_EXPECTED_ROOT: root };
const headers = { 'X-Labdoo-E2e': 'integration', 'X-Labdoo-E2e-Root': createHash('sha256').update(root).digest('hex') };
test('the current isolated checkout passes all request checks', () => {
  const run = scenario(environment, { status: 200, headers });
  run.setup();
  run.iteration();
  assert.equal(run.checks.length, 3);
  assert.ok(run.checks.every(check => check.passed));
  assert.deepEqual(run.options.thresholds.checks, ['rate==1']);
});
test('a stale server from another checkout fails validation', () => {
  const run = scenario(environment, { status: 200, headers: { ...headers, 'X-Labdoo-E2e-Root': 'another-checkout' } });
  run.iteration();
  assert.equal(run.checks.find(check => check.name === 'response is from this checkout').passed, false);
  assert.throws(() => run.setup(), /not the isolated site/);
});
test('an unguarded server and an HTTP error fail validation', () => {
  const run = scenario(environment, { status: 500, headers: {} });
  run.iteration();
  assert.ok(run.checks.every(check => !check.passed));
});
test('local runs require checkout identity and reject arbitrary origins', () => {
  assert.throws(() => scenario({}, {}), /LABDOO_EXPECTED_ROOT/);
  assert.throws(() => scenario({ ...environment, BASE_URL: 'https://v3.labdoo.org' }, {}), /restricted to the isolated local clone/);
});
