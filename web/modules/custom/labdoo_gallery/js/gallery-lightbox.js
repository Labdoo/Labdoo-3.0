(function (Drupal, once) {
  'use strict';
  const dialogs = new WeakMap();

  Drupal.behaviors.labdooGalleryLightbox = {
    attach(context) {
      once('labdoo-gallery-lightbox', '.labdoo-gallery-grid', context).forEach((grid) => {
        if (typeof HTMLDialogElement === 'undefined') return;
        const links = Array.from(grid.querySelectorAll('a')).filter((link) => link.querySelector('img'));
        if (!links.length) return;

        const dialog = document.createElement('dialog');
        dialog.className = 'labdoo-gallery-lightbox';
        dialog.setAttribute('aria-label', Drupal.t('Photo viewer'));
        dialog.innerHTML = '<div class="labdoo-gallery-lightbox__toolbar"><button type="button" data-action="previous"></button><span role="status" aria-live="polite"></span><button type="button" data-action="next"></button><button type="button" data-action="close"></button></div><figure><img alt=""><figcaption></figcaption></figure>';
        const image = dialog.querySelector('img');
        const caption = dialog.querySelector('figcaption');
        const status = dialog.querySelector('[role="status"]');
        const previous = dialog.querySelector('[data-action="previous"]');
        const next = dialog.querySelector('[data-action="next"]');
        const close = dialog.querySelector('[data-action="close"]');
        previous.textContent = Drupal.t('Previous');
        next.textContent = Drupal.t('Next');
        close.textContent = Drupal.t('Close');
        close.autofocus = true;
        previous.disabled = next.disabled = links.length < 2;
        let index = 0;
        const show = (position) => {
          index = (position + links.length) % links.length;
          const thumbnail = links[index].querySelector('img');
          image.src = links[index].href;
          image.alt = thumbnail.alt;
          caption.textContent = thumbnail.alt;
          status.textContent = Drupal.t('@current of @total', { '@current': index + 1, '@total': links.length });
        };
        previous.addEventListener('click', () => show(index - 1));
        next.addEventListener('click', () => show(index + 1));
        close.addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
          if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('keydown', (event) => {
          if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            show(index + (event.key === 'ArrowRight' ? 1 : -1));
          }
        });
        dialog.addEventListener('close', () => {
          document.documentElement.classList.remove('labdoo-gallery-lightbox-open');
        });
        links.forEach((link, position) => {
          link.addEventListener('click', (event) => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            show(position);
            dialog.showModal();
            document.documentElement.classList.add('labdoo-gallery-lightbox-open');
          });
        });
        grid.insertAdjacentElement('afterend', dialog);
        dialogs.set(grid, dialog);
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('labdoo-gallery-lightbox', '.labdoo-gallery-grid', context).forEach((grid) => {
          const dialog = dialogs.get(grid);
          if (dialog) {
            if (dialog.open) dialog.close();
            dialog.remove();
            dialogs.delete(grid);
          }
        });
      }
    },
  };
})(Drupal, once);
