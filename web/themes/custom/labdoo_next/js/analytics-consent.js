(function (Drupal, once, $, drupalSettings) {
  'use strict';
  Drupal.behaviors.labdooNextAnalyticsConsent = {
    attach(context) {
      once('labdoo-next-analytics-consent', 'html', context).forEach(() => {
        const accounts = drupalSettings.labdooNext.analyticsAccounts || [];
        const originalOptOut = new Map(accounts.map(account => [account, window['ga-disable-' + account] === true]));
        function update() {
          const allowed = Drupal.eu_cookie_compliance.hasAgreed();
          accounts.forEach(account => {
            window['ga-disable-' + account] = !allowed || originalOptOut.get(account);
          });
          if (!allowed) return;
          document.querySelectorAll('script[data-labdoo-analytics-src]').forEach(placeholder => {
            if (placeholder.dataset.labdooAnalyticsLoaded) return;
            placeholder.dataset.labdooAnalyticsLoaded = 'true';
            const script = document.createElement('script');
            script.async = true;
            script.src = placeholder.dataset.labdooAnalyticsSrc;
            script.onerror = () => {
              delete placeholder.dataset.labdooAnalyticsLoaded;
              script.remove();
            };
            placeholder.after(script);
          });
        }
        $(document).on('eu_cookie_compliance.changeStatus.labdooNextAnalytics', update);
        update();
      });
    },
  };
})(Drupal, once, jQuery, drupalSettings);
