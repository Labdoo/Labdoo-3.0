import http from 'k6/http';
import { check, sleep } from 'k6';
import crypto from 'k6/crypto';

// Complement the unchanged public reference scenario with authenticated polling.
export const options = {
  vus: 5, duration: '30s',
  thresholds: { http_req_duration: ['p(95)<2000'], http_req_failed: ['rate<0.02'], checks: ['rate==1'] },
};
const base = 'http://127.0.0.1:18411';
const path = __ENV.TRANSLATION_STATUS_PATH;
if (!__ENV.LABDOO_EXPECTED_ROOT || !__ENV.TRANSLATION_COOKIE || !/^\/en\/admin\/config\/regional\/labdoo-translations\/[a-f0-9-]{36}\/status$/.test(path || '')) {
  throw new Error('Provide this checkout, an isolated operator cookie and a local translation status path');
}
const params = { headers: { Cookie: __ENV.TRANSLATION_COOKIE } };
function identity(response) {
  const headers = Object.fromEntries(Object.entries(response.headers).map(([name, value]) => [name.toLowerCase(), value]));
  return headers['x-labdoo-e2e'] === 'integration' && headers['x-labdoo-e2e-root'] === crypto.sha256(__ENV.LABDOO_EXPECTED_ROOT, 'hex');
}
export function setup() {
  const response = http.get(base + path, params);
  if (response.status !== 200 || !identity(response)) throw new Error('Incorrect isolated status target');
}
export default function () {
  const response = http.get(base + path, params);
  check(response, {
    'authorized JSON status': r => r.status === 200 && r.json('state') === 'review' && r.json('completed') === 15,
    'isolated checkout': identity,
    'private status is not cached': r => Object.entries(r.headers).some(([key, value]) => key.toLowerCase() === 'cache-control' && value.includes('no-store')),
  });
  sleep(1);
}
