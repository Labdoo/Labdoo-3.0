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

const base = 'http://127.0.0.1:18411';
const root = __ENV.LABDOO_EXPECTED_ROOT;
const token = __ENV.LABDOO_MIGRATION_TEST_TOKEN || '';
const nodeId = __ENV.LABDOO_MIGRATION_NODE_ID || '';
if (!root || !/^[a-f0-9]{16}$/.test(token) || !/^[1-9][0-9]*$/.test(nodeId)) {
  throw new Error('Use an owned migration contract fixture and this checkout’s isolated local clone');
}
const identity = crypto.sha256(root, 'hex');
const checkout = r => Object.entries(r.headers).some(([key, value]) => key.toLowerCase() === 'x-labdoo-e2e-root' && value === identity);
const isolated = r => Object.entries(r.headers).some(([key, value]) => key.toLowerCase() === 'x-labdoo-e2e' && value === 'integration');

export function setup() {
  const response = http.get(`${base}/en/node/${nodeId}`);
  if (response.status !== 200 || !checkout(response) || !isolated(response) || !response.body.includes(token)) {
    throw new Error('Refusing load outside the isolated owned fixture');
  }
}

export default function () {
  const language = (__VU + __ITER) % 2 ? 'en' : 'es';
  const response = http.get(`${base}/${language}/node/${nodeId}`);
  check(response, {
    'HTTP 200': r => r.status === 200,
    'checkout identity': checkout,
    'isolated clone': isolated,
    'mapped title preserved': r => r.html().find('h1').text().includes(`E2E contenido migrado ${token}`),
    'mapped body preserved': r => r.html().find('main').text().includes(`Contenido aislado en español ${token}`),
    'published comment preserved': r => r.html().find('article.comment').text().includes(`Mapped comment body ${token}`),
    'unpublished comment hidden from anonymous visitors': r => !r.html().find('main').text().includes(`Mapped unpublished comment body ${token}`),
  });
  sleep(1);
}
