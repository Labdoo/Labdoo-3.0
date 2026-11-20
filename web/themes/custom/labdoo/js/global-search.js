/**
 * @file
 * Accessible search dialogs and cancellable, per-field autocomplete requests.
 */
(($, Drupal, once) => {
  const fields = context => $(context).find('.search-overlay input.form-autocomplete')
    .add($(context).filter('.search-overlay input.form-autocomplete'));

  Drupal.behaviors.labdooGlobalSearch = {
    attach(context) {
      once('labdoo-search-dialog', '.search-overlay-block', context).forEach(block => {
        const trigger = block.querySelector('.search-icon-trigger');
        const overlay = block.querySelector('.search-overlay');
        const closeButton = block.querySelector('.search-overlay-close');
        if (!trigger || !overlay || !closeButton) return;
        const close = () => {
          $(overlay).find('input.form-autocomplete').each(function () {
            const field = $(this);
            if (field.data('ui-autocomplete')) field.autocomplete('close');
            field.data('labdooSearchAbort')?.();
          });
          overlay.classList.remove('active');
          document.body.classList.remove('search-overlay-open');
          if (overlay instanceof HTMLDialogElement && overlay.open) overlay.close();
          if (trigger.isConnected) trigger.focus();
        };
        trigger.addEventListener('click', event => {
          event.preventDefault();
          if (overlay instanceof HTMLDialogElement && !overlay.open) overlay.showModal();
          overlay.classList.add('active');
          document.body.classList.add('search-overlay-open');
          overlay.querySelector('input[type="text"]')?.focus();
        });
        closeButton.addEventListener('click', event => { event.preventDefault(); close(); });
        overlay.addEventListener('keydown', event => {
          if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
          }
          if (event.key === 'Tab') {
            const controls = Array.from(overlay.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'))
              .filter(element => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
              event.preventDefault();
              last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
              event.preventDefault();
              first?.focus();
            }
          }
        });
        overlay.addEventListener('cancel', event => { event.preventDefault(); close(); });
        overlay.addEventListener('close', () => {
          overlay.classList.remove('active');
          document.body.classList.remove('search-overlay-open');
        });
        overlay.addEventListener('click', event => {
          if (event.target !== overlay) return;
          const box = overlay.getBoundingClientRect();
          if (!(overlay instanceof HTMLDialogElement) || event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close();
        });
        $(block).data('labdooSearchClose', close);
      });

      // This attachment is independent of the surrounding block's once marker.
      // AJAX may replace a form without replacing its already initialized dialog.
      fields(context).each(function () {
        const input = this;
        const field = $(input);
        const widget = field.data('ui-autocomplete');
        if (!widget || !once('labdoo-search-input', input).length) return;
        let active = null;
        const abort = () => {
          if (!active) return;
          const previous = active;
          active = null;
          previous.abort();
        };
        field.data('labdooSearchAbort', abort);
        field.attr('aria-label', field.attr('aria-label') || Drupal.t('Search'));
        field.autocomplete('option', {
          minLength: Math.max(3, widget.options.minLength),
          delay: 450,
          appendTo: field.closest('.search-overlay-content'),
          source(request, response) {
            abort();
            let settled = false;
            const finish = items => {
              if (settled) return;
              settled = true;
              const tagged = Drupal.autocomplete.splitValues(request.term);
              response(Array.isArray(items) ? items.filter(item => !tagged.includes(item)).slice(0, 5) : []);
            };
            const term = Drupal.autocomplete.extractLastTerm(request.term);
            const cache = Drupal.autocomplete.cache[input.id] ||= {};
            if (Object.prototype.hasOwnProperty.call(cache, term)) { finish(cache[term]); return; }
            let current;
            current = $.ajax(input.getAttribute('data-autocomplete-path'), {
              ...Drupal.autocomplete.ajax,
              data: { q: term },
              success(items) {
                if (Array.isArray(items)) cache[term] = items;
                finish(items);
              },
              error() { finish([]); },
              complete() { if (active === current) active = null; },
            });
            active = current.readyState === 4 ? null : current;
          },
        });
        field.on('input.labdooGlobalSearch', () => {
          if (Drupal.autocomplete.extractLastTerm(input.value).length < widget.options.minLength) {
            field.autocomplete('close');
            abort();
          }
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger !== 'unload') return;
      $(context).find('.search-overlay-block').add($(context).filter('.search-overlay-block')).each(function () {
        $(this).data('labdooSearchClose')?.();
      });
      fields(context).each(function () {
        $(this).data('labdooSearchAbort')?.();
        $(this).off('.labdooGlobalSearch').removeData('labdooSearchAbort');
        once.remove('labdoo-search-input', this);
      });
    },
  };
})(jQuery, Drupal, once);
