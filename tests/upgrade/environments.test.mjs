import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { loadEnvironments } from './environments.mjs';

function withEnvironment(callback) {
  const previous = Object.fromEntries(Object.entries(process.env).filter(([key]) => key.startsWith('LABDOO_TEST_')));
  for (const key of Object.keys(previous)) delete process.env[key];
  try { callback(); }
  finally {
    for (const key of Object.keys(process.env)) if (key.startsWith('LABDOO_TEST_')) delete process.env[key];
    Object.assign(process.env, previous);
  }
}

test('dashboard discovers configured environments in the active repository root', t => {
  const expectedFile = fileURLToPath(new URL('../../.env', import.meta.url));
  t.mock.method(fs, 'existsSync', file => file === expectedFile);
  t.mock.method(fs, 'readFileSync', file => {
    assert.equal(file, expectedFile);
    return 'LABDOO_TEST_ENVIRONMENTS=local,staging\nLABDOO_TEST_ENV_STAGING_URL=https://staging.example.org\n';
  });
  withEnvironment(() => {
    const result = loadEnvironments();
    assert.equal(result.environments.staging.url, 'https://staging.example.org');
    assert.equal(result.environments.local.url, 'http://127.0.0.1:18411');
    assert.equal(result.environments.staging.allowK6, false);
  });
});

test('a writable test environment cannot target a remote deployment', t => {
  t.mock.method(fs, 'existsSync', () => false);
  withEnvironment(() => {
    process.env.LABDOO_TEST_ENV_LOCAL_URL = 'https://production.example.org';
    assert.throws(loadEnvironments, /isolated E2E environment must be local/);
  });
});
