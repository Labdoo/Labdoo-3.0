(function (Drupal, once) {
  'use strict';

  if (!('IntersectionObserver' in window)) {
    Object.values(window.drupalSettings?.geolocation?.maps || {}).forEach((settings) => {
      if (settings.conditional_initialization === 'viewport') settings.conditional_initialization = 'no';
    });
  }

  const excluded = /\/(?:admin(?:\/|$)|user\/logout|dootronics\/(?:print-labels|export)|content\/tag-one-dooject)|\.(?:pdf|png|jpe?g|gif|svg|zip|csv)$/i;
  Drupal.behaviors.labdooNext = {
    attach(context) {
      once('labdoo-next-link', 'a[href]', context).forEach((link) => {
        if (link.hasAttribute('data-labdoo-original') || link.hasAttribute('download')) return;
        const raw = link.getAttribute('href');
        if (!raw || raw.startsWith('#') || /^(?:mailto:|tel:|javascript:)/i.test(raw)) return;
        const url = new URL(raw, document.baseURI);
        if (url.origin !== location.origin || excluded.test(url.pathname)) return;
        url.searchParams.set('theme', 'labdoo_next');
        link.href = url.href;
      });
      once('labdoo-next-form', 'form', context).forEach((form) => {
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') return;
        const url = new URL(form.action || location.href, document.baseURI);
        if (url.origin !== location.origin || excluded.test(url.pathname)) return;
        let input = form.querySelector('input[name="theme"]');
        if (!input) {
          input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'theme';
          form.append(input);
        }
        input.value = 'labdoo_next';
      });
      once('labdoo-next-table', '.ldn-main table', context).forEach((table) => {
        const scroll = document.createElement('div');
        scroll.className = 'ldn-table-scroll';
        scroll.tabIndex = 0;
        scroll.setAttribute('role', 'region');
        scroll.setAttribute('aria-label', table.querySelector('caption')?.textContent || Drupal.t('Results table'));
        table.before(scroll);
        scroll.append(table);
      });
      once('labdoo-next-menu', '.ldn-menu-toggle', context).forEach((toggle) => {
        const nav = document.getElementById(toggle.getAttribute('aria-controls'));
        toggle.addEventListener('click', () => {
          const open = toggle.getAttribute('aria-expanded') !== 'true';
          toggle.setAttribute('aria-expanded', String(open));
          nav.classList.toggle('is-open', open);
        });
        nav.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            nav.querySelectorAll('details[open]').forEach(d => { d.open = false; });
            nav.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
          }
        });
      });
    },
  };
})(Drupal, once);
