import http from 'k6/http';
import { check, sleep } from 'k6';

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
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:18411';
if (!/^http:\/\/(127\.0\.0\.1|localhost):18411$/.test(BASE_URL)) {
  throw new Error('The Labdoo k6 suite is restricted to the isolated local clone at http://127.0.0.1:18411');
}

// Keep the routes from the existing Labdoo load scenario.
const paths = [
  '/',
  '/en/actions',
  '/en/content/dootronics-dashboard',
  '/en/content/000000011-0',
  '/de/content/informare-edoovillages-proiecte',
];

export default function () {
  const path = paths[Math.floor(Math.random() * paths.length)];
  const response = http.get(`${BASE_URL}${path}`);

  check(response, {
    'status is below 400': (r) => r.status >= 200 && r.status < 400,
    'response is from the isolated E2E clone': (r) => r.headers['X-Labdoo-E2E'] === 'integration',
  });

  sleep(1);
}
