import http from 'k6/http';
import { check, sleep } from 'k6';
import crypto from 'k6/crypto';

export const options = {
  stages: [
    { duration: '2m', target: 20 },
    { duration: '3m', target: 50 },
    { duration: '5m', target: 100 },
    { duration: '3m', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<2000'],
    http_req_failed: ['rate<0.02'],
    checks: ['rate==1'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:18411';
const localTarget = /^http:\/\/(127\.0\.0\.1|localhost):18411$/.test(BASE_URL);
if (!localTarget) {
  throw new Error('The Labdoo k6 suite is restricted to the isolated local clone at http://127.0.0.1:18411.');
}
const expectedRoot = __ENV.LABDOO_EXPECTED_ROOT;
if (localTarget && !expectedRoot) throw new Error('Set LABDOO_EXPECTED_ROOT to the checkout being tested');
const requestParams = {};

const paths = [
  '/',
  '/en/actions',
  '/en/content/dootronics-dashboard',
  '/en/content/000046648',
  '/de/content/information-fuer-edoovillages-schulen',
];

// Refuse a stale or unguarded local server before generating sustained load.
export function setup() {
  const response = http.get(`${BASE_URL}/`, requestParams);
  const identity = Object.fromEntries(Object.entries(response.headers).map(([name, value]) => [name.toLowerCase(), value]));
  if (response.status !== 200 || identity['x-labdoo-e2e'] !== 'integration' || identity['x-labdoo-e2e-root'] !== crypto.sha256(expectedRoot, 'hex')) {
    throw new Error('k6 target is not the isolated site for this checkout');
  }
}

export default function () {
  const path = paths[Math.floor(Math.random() * paths.length)];
  const response = http.get(`${BASE_URL}${path}`, requestParams);

  check(response, {
    'status is below 400': (r) => r.status >= 200 && r.status < 400,
    'response is from this checkout': (r) => Object.entries(r.headers).some(([name, value]) => name.toLowerCase() === 'x-labdoo-e2e-root' && value === crypto.sha256(expectedRoot, 'hex')),
    'response is from the isolated E2E clone': (r) => Object.entries(r.headers).some(([name, value]) => name.toLowerCase() === 'x-labdoo-e2e' && value === 'integration'),
  });

  sleep(1);
}
