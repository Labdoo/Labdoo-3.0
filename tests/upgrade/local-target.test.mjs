import { test } from 'node:test';
import assert from 'node:assert/strict';
import { localTarget, selectTarget } from './local-target.mjs';

test('the test lab defaults to its isolated local clone', () => {
  assert.equal(selectTarget(), localTarget);
  assert.equal(selectTarget('local'), localTarget);
  assert.equal(localTarget.url, 'http://127.0.0.1:18411');
});

test('the test lab rejects remote environment selections', () => {
  assert.throws(() => selectTarget('v3'), /only supports its isolated local environment/);
  assert.throws(() => selectTarget('staging'), /only supports its isolated local environment/);
});
