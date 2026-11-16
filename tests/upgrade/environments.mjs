import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function parseEnvFile(file) {
  if (!file || !fs.existsSync(file)) return {};
  const values = {};
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const match = line.match(/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/);
    if (!match) continue;
    let value = match[2];
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    } else {
      value = value.replace(/\s+#.*$/, '').trim();
    }
    values[match[1]] = value;
  }
  return values;
}

function enabled(value) {
  return ['1', 'true', 'yes', 'on'].includes(String(value || '').toLowerCase());
}

export function loadEnvironments() {
  const envFile = process.env.LABDOO_TEST_ENV_FILE || path.join(repoRoot, '.env');
  const values = { ...parseEnvFile(envFile), ...process.env };
  const ids = [...new Set(['local', ...(values.LABDOO_TEST_ENVIRONMENTS || 'local').split(',').map(id => id.trim().toLowerCase()).filter(Boolean)])];
  if (!ids.length || ids.length > 12) throw new Error('LABDOO_TEST_ENVIRONMENTS must list between 1 and 12 environments.');

  const environments = {};
  for (const id of ids) {
    if (!/^[a-z][a-z0-9_-]{0,31}$/.test(id)) throw new Error(`Invalid test environment id: ${id}`);
    const prefix = `LABDOO_TEST_ENV_${id.toUpperCase()}_`;
    const mode = (values[`${prefix}MODE`] || (id === 'local' ? 'isolated' : 'readonly')).toLowerCase();
    if (!['isolated', 'readonly'].includes(mode)) throw new Error(`Unsupported mode for test environment ${id}.`);
    const fallbackUrl = id === 'local' ? 'http://127.0.0.1:18411' : '';
    const url = String(values[`${prefix}URL`] || fallbackUrl).trim().replace(/\/+$/, '');
    let parsed;
    try { parsed = new URL(url); } catch { throw new Error(`Set a valid ${prefix}URL in ${envFile}.`); }
    if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password || parsed.search || parsed.hash || parsed.pathname !== '/') {
      throw new Error(`${prefix}URL must be an HTTP(S) origin without credentials, query, or path.`);
    }
    const isLoopback = ['127.0.0.1', 'localhost', '::1'].includes(parsed.hostname);
    if (mode === 'isolated' && (id !== 'local' || url !== 'http://127.0.0.1:18411')) {
      throw new Error('The isolated E2E environment must be local at http://127.0.0.1:18411.');
    }
    if (mode === 'readonly' && (parsed.protocol !== 'https:' || isLoopback)) {
      throw new Error(`Remote environment ${id} must use an HTTPS host.`);
    }
    const username = values[`${prefix}HTTP_USER`] || '';
    const password = values[`${prefix}HTTP_PASSWORD`] || '';
    if (Boolean(username) !== Boolean(password)) throw new Error(`Set both ${prefix}HTTP_USER and ${prefix}HTTP_PASSWORD, or neither.`);
    environments[id] = {
      id,
      name: values[`${prefix}NAME`] || (id === 'local' ? 'Local test clone' : id),
      url: parsed.origin,
      mode,
      allowK6: mode === 'isolated' || enabled(values[`${prefix}ALLOW_K6`]),
      allowLighthouse: mode === 'isolated' || (enabled(values[`${prefix}ALLOW_LIGHTHOUSE`]) && !username),
      httpUser: username,
      httpPassword: password,
    };
  }
  const defaultId = (values.LABDOO_TEST_DEFAULT_ENV || 'local').trim().toLowerCase();
  if (!Object.hasOwn(environments, defaultId)) throw new Error('LABDOO_TEST_DEFAULT_ENV must name a configured environment.');
  return { environments, defaultId };
}
