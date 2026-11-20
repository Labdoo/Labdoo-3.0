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
if (base !== 'http://127.0.0.1:18411' || !root) throw new Error('Use this checkout’s isolated local site and set LABDOO_EXPECTED_ROOT');
const identity = crypto.sha256(root, 'hex');
const fromCheckout = response => Object.entries(response.headers).some(([name, value]) => name.toLowerCase() === 'x-labdoo-e2e-root' && value === identity);
const isolated = response => Object.entries(response.headers).some(([name, value]) => name.toLowerCase() === 'x-labdoo-e2e' && value === 'integration');

export function setup() {
  const response = http.get(base + '/en');
  if (response.status !== 200 || !fromCheckout(response) || !isolated(response)) throw new Error('Refusing load outside the isolated checkout');
  const endpoint = response.html().find('.search-overlay input[data-autocomplete-path]').attr('data-autocomplete-path');
  if (!endpoint || !endpoint.startsWith('/') || endpoint.startsWith('//')) throw new Error('Expected the actual same-origin autocomplete endpoint');
  return { endpoint };
}

export default function ({ endpoint }) {
  const term = (__ITER + __VU) % 2 ? 'Dootrip' : 'Dootronics';
  const response = http.get(base + endpoint + (endpoint.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(term));
  let items;
  try { items = response.json(); } catch (_) { items = null; }
  check(response, {
    'HTTP 200': r => r.status === 200,
    'checkout identity': fromCheckout,
    'isolated clone': isolated,
    'real suggestions have labels and destinations': () => Array.isArray(items) && items.length > 0 && items.every(item => typeof item.label === 'string' && typeof item.url === 'string' && item.url.length > 0),
  });
  sleep(1);
}
