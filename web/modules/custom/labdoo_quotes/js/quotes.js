(function (Drupal, once) {
  'use strict';

  const storageKey = 'labdoo.educationQuote.last';
  const requests = new Map();
  function remember(id) {
    try { sessionStorage.setItem(storageKey, id); }
    catch (error) { /* Quote loading also works when storage is disabled. */ }
  }
  function requestQuote(element) {
    const endpoint = element.dataset.quoteUrl;
    if (!requests.has(endpoint)) {
      const url = new URL(endpoint, document.baseURI);
      let previous = element.dataset.quoteId;
      try { previous = sessionStorage.getItem(storageKey) || previous; }
      catch (error) { /* Exclude the fallback when storage is disabled. */ }
      url.searchParams.set('exclude', previous);
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 8000);
      requests.set(endpoint, fetch(url, {
        signal: controller.signal,
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      }).then(response => {
        if (!response.ok) throw new Error('Quote request failed');
        return response.json();
      }).then(data => {
        const quote = data.quote;
        if (data.language !== element.lang || !quote ||
            !['id', 'text', 'author', 'source'].every(key => typeof quote[key] === 'string') ||
            new URL(quote.source).protocol !== 'https:') {
          throw new Error('Invalid quote response');
        }
        remember(quote.id);
        return quote;
      }).finally(() => clearTimeout(timeout)));
    }
    return requests.get(endpoint);
  }
  function load(element) {
    element.setAttribute('data-quote-pending', '');
    element.setAttribute('aria-busy', 'true');
    requestQuote(element).then(quote => {
      if (!element.isConnected) return;
      element.dataset.quoteId = quote.id;
      element.querySelector('.labdoo-quote__text').textContent = quote.text;
      const author = element.querySelector('.labdoo-quote__author');
      author.textContent = quote.author;
      author.href = quote.source;
      element.querySelector('blockquote').setAttribute('cite', quote.source);
    }).catch(() => {
      // Keep the translated server-rendered quote on network or server failure.
    }).finally(() => {
      element.removeAttribute('data-quote-pending');
      element.setAttribute('aria-busy', 'false');
    });
  }
  Drupal.behaviors.labdooEducationQuotes = {
    attach(context) {
      once('labdoo-education-quote', '.labdoo-quote', context).forEach(load);
    },
  };
  window.addEventListener('pageshow', event => {
    if (event.persisted) {
      requests.clear();
      document.querySelectorAll('.labdoo-quote').forEach(load);
    }
  });
})(Drupal, once);
