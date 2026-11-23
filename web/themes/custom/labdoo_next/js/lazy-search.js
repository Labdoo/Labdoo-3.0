/**
 * @file
 * Loads search enhancements on first use through Drupal's AJAX asset pipeline.
 */
((Drupal, once, drupalSettings) => {
  'use strict';
  let pending;

  function load(url) {
    if (!pending) {
      pending = new Promise((resolve, reject) => {
        const ajax = Drupal.ajax({ url, httpMethod: 'GET', progress: false });
        // Wait for asset commands, not just the underlying HTTP response.
        ajax.success = (response, status) => ajax.commandExecutionQueue(response, status)
          .then(() => {
            Drupal.attachBehaviors(document, drupalSettings);
            if (!Drupal.behaviors.labdooGlobalSearch) throw new Error('Search assets were not loaded');
            resolve();
          }).catch(reject);
        ajax.error = () => reject(new Error('Search assets could not be loaded'));
        ajax.execute()?.fail(() => reject(new Error('Search asset request failed')));
      }).catch(error => { pending = undefined; throw error; });
    }
    return pending;
  }

  Drupal.behaviors.labdooNextSearchLoader = {
    attach(context) {
      once('labdoo-next-search-loader', '.ldn-search-block', context).forEach(block => {
        const trigger = block.querySelector('.search-icon-trigger');
        let opening = false;
        trigger.addEventListener('click', event => {
          if (Drupal.behaviors.labdooGlobalSearch) return;
          event.preventDefault();
          event.stopImmediatePropagation();
          if (opening) return;
          opening = true;
          trigger.setAttribute('aria-busy', 'true');
          load(block.dataset.searchAssets).then(() => {
            opening = false;
            trigger.removeAttribute('aria-busy');
            if (trigger.isConnected) trigger.click();
          }).catch(() => {
            trigger.removeAttribute('aria-busy');
            window.location.assign(trigger.href);
          });
        }, true);
      });
    },
  };
})(Drupal, once, drupalSettings);
