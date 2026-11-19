(function (Drupal, once) {
  'use strict';

  if (!('IntersectionObserver' in window)) {
    Object.values(window.drupalSettings?.geolocation?.maps || {}).forEach((settings) => {
      if (settings.conditional_initialization === 'viewport') settings.conditional_initialization = 'no';
    });
  }

  Drupal.behaviors.labdooNext = {
    attach(context) {
      once('labdoo-next-table', '.ldn-main table', context).forEach((table) => {
        if (table.closest('.ldn-table-scroll')) return;
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
        const utilities = toggle.closest('.ldn-header__inner').querySelector('.ldn-utilities');
        const mobile = window.matchMedia('(max-width: 1100px)');
        if (utilities) {
          // Move the existing controls so their forms and event handlers stay intact.
          const home = document.createComment('Header utilities');
          utilities.before(home);
          const placeUtilities = () => {
            if (mobile.matches) nav.prepend(utilities);
            else home.after(utilities);
          };
          mobile.addEventListener('change', placeUtilities);
          placeUtilities();
        }
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
