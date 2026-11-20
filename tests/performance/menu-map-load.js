import http from 'k6/http';
import { check, sleep } from 'k6';
import crypto from 'k6/crypto';

export const options = {
  vus: 10,
  duration: '1m',
  thresholds: {
    http_req_duration: ['p(95)<2000'],
    http_req_failed: ['rate<0.02'],
    checks: ['rate==1'],
  },
};
const base = __ENV.BASE_URL || 'http://127.0.0.1:18411';
const root = __ENV.LABDOO_EXPECTED_ROOT;
const legacy = __ENV.MAP_FORMAT === 'legacy';
if (base !== 'http://127.0.0.1:18411' || !root) throw new Error('Use this checkout’s isolated local site and set LABDOO_EXPECTED_ROOT');
const identity = crypto.sha256(root, 'hex');
const fromCheckout = response => Object.entries(response.headers).some(([name, value]) => name.toLowerCase() === 'x-labdoo-e2e-root' && value === identity);
const isolated = response => Object.entries(response.headers).some(([name, value]) => name.toLowerCase() === 'x-labdoo-e2e' && value === 'integration');
export function setup() {
  const response = http.get(base + '/en');
  if (response.status !== 200 || !fromCheckout(response) || !isolated(response)) throw new Error('Refusing load outside the isolated checkout');
}
export default function () {
  const types = ['dootronic', 'dootrip', 'edoovillage', 'hub'];
  const type = types[(__ITER + __VU) % types.length];
  const response = http.get(base + '/en/api/map-points/' + type + (legacy ? '' : '?_map=clusters&zoom=2'));
  let data;
  try { data = response.json(); } catch (_) { data = null; }
  check(response, {
    'HTTP 200': r => r.status === 200,
    'checkout identity': fromCheckout,
    'isolated clone': isolated,
    'map data and counts are intact': () => legacy ? Array.isArray(data) : data && Array.isArray(data.points) && data.total > 0 && data.points.reduce((sum, point) => sum + point.count, 0) === data.total,
  });
  sleep(1);
}
