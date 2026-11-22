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
  const response = http.get(base + '/en');
  if (response.status !== 200 || !checkout(response) || !isolated(response)) {
    throw new Error('Refusing load outside the isolated checkout');
  }
}

export default function () {
  const language = (__VU + __ITER) % 2 ? 'en' : 'es';
  const query = `type=contact&language=${language}&MESSAGE=Quality%20body&EMAIL=notification%40example.invalid`;
  const response = http.get(`${base}/${language}/content/notification-email?${query}`);
  const acknowledgement = language === 'en' ? 'We received your message' : 'Recibimos tu mensaje';
  check(response, {
    'HTTP 200': r => r.status === 200,
    'checkout identity': checkout,
    'isolated clone': isolated,
    'localized acknowledgement': r => r.body.includes(acknowledgement),
    'message preserved': r => r.html().find('main').text().includes('Quality body'),
    'tokens resolved': r => !r.html().find('main').text().includes('[MESSAGE]') && !r.html().find('main').text().includes('[LANGUAGE_MENU]'),
  });
  sleep(1);
}
