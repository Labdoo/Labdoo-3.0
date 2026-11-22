import http from 'k6/http';
import { check, sleep } from 'k6';
import crypto from 'k6/crypto';

// Focused statistics coverage, using the existing endpoint-load test budgets.
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
const checkout = response => Object.entries(response.headers).some(([key, value]) => key.toLowerCase() === 'x-labdoo-e2e-root' && value === identity);
const isolated = response => Object.entries(response.headers).some(([key, value]) => key.toLowerCase() === 'x-labdoo-e2e' && value === 'integration');

export function setup() {
  const response = http.get(base + '/en');
  if (response.status !== 200 || !checkout(response) || !isolated(response)) {
    throw new Error('Refusing load outside the isolated checkout');
  }
}

export default function () {
  const language = (__VU + __ITER) % 2 ? 'en' : 'es';
  const response = http.get(`${base}/${language}/statistics/advanced`);
  check(response, {
    'HTTP 200': r => r.status === 200,
    'checkout identity': checkout,
    'isolated clone': isolated,
    'platform age is rendered': r => r.html().find('.platform-uptime-banner strong').size() === 3
      && r.html().find('.platform-uptime-banner small').text().match(/\d+/) !== null,
  });
  sleep(1);
}
