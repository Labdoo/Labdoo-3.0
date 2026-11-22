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
if (base !== 'http://127.0.0.1:18411' || !root) throw new Error('Use the isolated local checkout only');
const identity = crypto.sha256(root, 'hex');
const header = (response, name) => Object.entries(response.headers).find(([key]) => key.toLowerCase() === name)?.[1];
const guarded = response => response.status === 200
  && header(response, 'x-labdoo-e2e') === 'integration'
  && header(response, 'x-labdoo-e2e-root') === identity;

export function setup() {
  const page = http.get(base + '/en');
  if (!guarded(page)) throw new Error('Refusing load outside this isolated checkout');
  const html = page.html();
  const css = html.find('link[rel="stylesheet"][href^="/sites/labdoo_e2e/files/css/"]').first().attr('href')
    || html.find('link[rel="stylesheet"][href^="/core/"]').first().attr('href')
    || html.find('link[rel="stylesheet"][href^="/themes/"]').first().attr('href');
  const js = html.find('script[src^="/sites/labdoo_e2e/files/js/"]').first().attr('src') || '/core/misc/drupal.js';
  if (![css, js].every(path => typeof path === 'string' && path.startsWith('/') && !path.startsWith('//'))) {
    throw new Error('Expected actual local static assets');
  }
  const image = '/themes/custom/labdoo_next/images/photo-album-icon-30.webp';
  // Compile aggregates and verify the responsive artwork before revalidation.
  for (const path of [css, js, image]) {
    if (http.get(base + path).status !== 200) throw new Error('Asset warmup failed');
  }
  return { paths: [css, js, image] };
}

export default function ({ paths }) {
  const page = http.get(base + '/en');
  check(page, { 'page belongs to isolated checkout': guarded });
  const path = paths[(__VU + __ITER) % paths.length];
  const asset = http.get(base + path);
  const etag = header(asset, 'etag');
  check(asset, {
    'asset HTTP 200': r => r.status === 200,
    'asset has CSS, JavaScript or WebP MIME': r => /(?:css|javascript|image\/webp)/i.test(header(r, 'content-type') || ''),
    'asset has an ETag': () => Boolean(etag),
    'asset is not an HTML error': r => !/^\s*(?:<!doctype html|<html)/i.test(r.body),
  });
  const conditional = http.get(base + path, { headers: { 'If-None-Match': etag || 'missing-etag' } });
  check(conditional, { 'unchanged asset returns HTTP 304': r => r.status === 304 });
  sleep(1);
}
