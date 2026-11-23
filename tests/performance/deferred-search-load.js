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
const port = __ENV.LABDOO_LOCAL_PORT || '18411';
if (!['18411', '18421'].includes(port) || base !== `http://127.0.0.1:${port}` || !root) throw new Error('Use the isolated current-checkout site and set LABDOO_EXPECTED_ROOT');
const identity = crypto.sha256(root, 'hex');
const fromCheckout = response => Object.entries(response.headers).some(([name, value]) => name.toLowerCase() === 'x-labdoo-e2e-root' && value === identity);
const isolated = response => Object.entries(response.headers).some(([name, value]) => name.toLowerCase() === 'x-labdoo-e2e' && value === 'integration');

export function setup() {
  const response = http.get(base + '/en');
  if (response.status !== 200 || !fromCheckout(response) || !isolated(response)) throw new Error('Refusing load outside the isolated checkout');
  const endpoint = response.html().find('.ldn-search-block[data-search-assets]').attr('data-search-assets');
  if (!endpoint || !endpoint.startsWith('/') || endpoint.startsWith('//')) throw new Error('Expected the actual same-origin search asset endpoint');
  return { endpoint };
}

export default function ({ endpoint }) {
  const response = http.get(base + endpoint + '?_wrapper_format=drupal_ajax&ajax_page_state%5Btheme%5D=labdoo_next');
  let commands;
  try { commands = response.json(); } catch (_) { commands = null; }
  const cache = Object.entries(response.headers).find(([name]) => name.toLowerCase() === 'cache-control')?.[1] || '';
  check(response, {
    'HTTP 200': r => r.status === 200,
    'checkout identity': fromCheckout,
    'isolated clone': isolated,
    'script commands supplied': () => Array.isArray(commands) && commands.some(command => command.command === 'add_js'),
    'stylesheet commands supplied': () => Array.isArray(commands) && commands.some(command => command.command === 'add_css'),
    'caller-specific assets remain private': () => cache.includes('private') && cache.includes('no-store'),
  });
  sleep(1);
}
