// Rendered inline before styles so the request can overlap their download.
(function (endpoint, fallback) {
  'use strict';
  document.documentElement.classList.add('labdoo-quotes-js');
  const requests = window.labdooEducationQuoteRequests ||= new Map();
  if (requests.has(endpoint)) return;
  let previous = fallback;
  try { previous = sessionStorage.getItem('labdoo.educationQuote.last') || fallback; }
  catch (error) { /* Storage is optional. */ }
  const url = new URL(endpoint, document.baseURI);
  url.searchParams.set('exclude', previous);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 8000);
  const pending = fetch(url, {
    signal: controller.signal,
    cache: 'no-store',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  }).then(response => {
    if (!response.ok) throw new Error('Quote request failed');
    return response.json();
  }).finally(() => clearTimeout(timeout));
  // The widget attaches later; keep early network failures handled until then.
  pending.catch(() => {});
  requests.set(endpoint, pending);
})
