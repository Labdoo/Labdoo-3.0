import http from 'k6/http';
import { check, sleep } from 'k6';
import crypto from 'k6/crypto';

export const options = {
  vus: 5,
  duration: '30s',
  thresholds: {
    http_req_duration: ['p(95)<2000'],
    http_req_failed: ['rate<0.02'],
    checks: ['rate==1'],
  },
};

const base = __ENV.BASE_URL || 'http://127.0.0.1:18411';
const root = __ENV.LABDOO_EXPECTED_ROOT;
if (base !== 'http://127.0.0.1:18411' || !root) {
  throw new Error('Use this checkout’s isolated local clone and set LABDOO_EXPECTED_ROOT');
}
const identity = crypto.sha256(root, 'hex');
const checkout = r => Object.entries(r.headers).some(([key, value]) => key.toLowerCase() === 'x-labdoo-e2e-root' && value === identity);
const isolated = r => Object.entries(r.headers).some(([key, value]) => key.toLowerCase() === 'x-labdoo-e2e' && value === 'integration');

export function setup() {
  const response = http.get(base + '/en/actions');
  if (response.status !== 200 || !checkout(response) || !isolated(response)) {
    throw new Error('Refusing load outside the isolated checkout');
  }
}

export default function () {
  const index = (__VU + __ITER) % 4;
  const language = index < 2 ? 'en' : 'es';
  const response = http.get(`${base}/${language}/actions${index % 2 ? '?mine=1' : ''}`);
  check(response, {
    'HTTP 200': r => r.status === 200,
    'checkout identity': checkout,
    'isolated clone': isolated,
    'actions view renders': r => r.html().find('main .view-id-actions').size() === 1,
    'unfiltered view has activity': r => index % 2 === 1 || r.html().find('main .view-id-actions tbody tr').size() > 0,
  });
  sleep(1);
}
