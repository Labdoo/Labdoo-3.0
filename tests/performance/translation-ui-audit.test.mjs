import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const runner = new URL('./translation-ui-audit.mjs', import.meta.url);
test('authenticated audits reject invalid jobs before opening a browser', () => {
  const result = spawnSync(process.execPath, [runner.pathname, '/missing/auth.json', 'https://example.org', '/tmp/unused-audit'], { encoding: 'utf8' });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /Usage:/);
});
test('foreign-origin cookies cannot authenticate an audit', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'labdoo-audit-guard-'));
  try {
    const file = path.join(directory, 'auth.json');
    fs.writeFileSync(file, JSON.stringify({ cookies: [{ name: 'SESSfixture', value: 'fixture', domain: 'example.org' }] }));
    const result = spawnSync(process.execPath, [runner.pathname, file, '00000000-0000-0000-0000-000000000000', directory], { encoding: 'utf8' });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /No isolated loopback operator session/);
  } finally { fs.rmSync(directory, { recursive: true }); }
});
