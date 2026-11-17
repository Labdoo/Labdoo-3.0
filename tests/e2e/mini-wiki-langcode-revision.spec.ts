import { test, expect, stateDir, variant } from './helpers';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

test('mini wiki language metadata remains revisionable', async ({}, info) => {
  const script = path.join(__dirname, 'support/check-mini-wiki-langcode-revision.php');
  const drush = path.join(stateDir, '../tools/drush.py');
  const { stdout } = await promisify(execFile)('python3', [drush, variant(info), 'php:script', script], { timeout: 30000 });
  expect(stdout).toContain('mini_wiki_langcode_revisionable=true');
  expect(stdout).toContain('mini_wiki_langcode_update_pending=false');
  expect(stdout).toContain('mini_wiki_path_computed=true');
  expect(stdout).toContain('mini_wiki_path_translatable=true');
  expect(stdout).toContain('mini_wiki_path_update_pending=false');
  expect(stdout).toContain('mini_wiki_schema_warnings=0');
});
