import http from 'k6/http';
import { check, sleep } from 'k6';
import crypto from 'k6/crypto';

// Each iteration obtains fresh CSRF/form state; all actions are estimate-only.
export const options = {
  vus: 5, duration: '30s',
  thresholds: { http_req_duration: ['p(95)<2000'], http_req_failed: ['rate<0.02'], checks: ['rate==1'] },
};
const base = 'http://127.0.0.1:18411';
const route = '/en/admin/config/regional/labdoo-translations';
if (!__ENV.LABDOO_EXPECTED_ROOT || !__ENV.TRANSLATION_COOKIE) {
  throw new Error('Provide an isolated checkout and operator session');
}
const params = { headers: { Cookie: __ENV.TRANSLATION_COOKIE } };
function identity(response) {
  const headers = Object.fromEntries(Object.entries(response.headers).map(([key, value]) => [key.toLowerCase(), value]));
  return headers['x-labdoo-e2e'] === 'integration' && headers['x-labdoo-e2e-root'] === crypto.sha256(__ENV.LABDOO_EXPECTED_ROOT, 'hex');
}
export function setup() {
  const response = http.get(base + route, params);
  if (response.status !== 200 || !identity(response)) throw new Error('Incorrect isolated estimate target');
}
function fields(response) {
  const document = response.html().find('form#labdoo-translation-jobs');
  const body = { form_id: 'labdoo_translation_jobs', 'languages[es]': 'es', 'languages[fr]': 'fr', agent: 'fixture' };
  for (const name of ['form_build_id', 'form_token', 'request_id']) {
    body[name] = document.find(`input[name="${name}"]`).attr('value');
    if (!body[name]) throw new Error('Missing isolated translation form state');
  }
  return body;
}
export default function () {
  const initial = http.get(base + route, params);
  const preview = http.post(base + route, { ...fields(initial), model: '', preview: 'Check pending strings' }, params);
  const response = http.post(base + route, { ...fields(preview), model: 'test-model', estimate: 'Estimate cost' }, params);
  check(response, {
    'estimate contains priced selected workload': r => r.status === 200 && r.body.includes('Estimated translation cost') && r.body.includes('USD') && r.body.includes('Simulated translator'),
    'no job redirect or form error': r => r.url === base + route && !r.body.includes('The estimate could not be calculated') && !r.body.includes('An illegal choice has been detected'),
    'isolated checkout': identity,
  });
  sleep(1);
}
