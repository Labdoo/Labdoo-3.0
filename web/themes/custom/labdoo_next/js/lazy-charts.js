(function (Drupal, once, drupalSettings) {
  'use strict';

  let loading;
  function loadCharts() {
    if (!loading) {
      const base = 'themes/custom/labdoo_next/vendor/';
      const paths = [
        base + 'chart.js-4.4.0/dist/chart.umd.js',
        base + 'chartjs-adapter-date-fns-3.0.0/dist/chartjs-adapter-date-fns.bundle.js',
        base + 'chartjs-plugin-datalabels-2.0.0/dist/chartjs-plugin-datalabels.js',
        drupalSettings.labdooNext.chartsBridge.replace(/^\//, ''),
      ];
      // Fetch together, execute in dependency order. Reuse the contrib renderer
      // and its chart settings rather than maintaining a second chart renderer.
      loading = Promise.all(paths.map((path) => new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = drupalSettings.path.baseUrl + path;
        script.async = false;
        script.onload = resolve;
        script.onerror = () => reject(new Error('Unable to load chart library: ' + path));
        document.head.append(script);
      })));
    }
    return loading;
  }

  Drupal.behaviors.labdooNextCharts = {
    attach(context) {
      once('labdoo-next-chart', '.charts-chartjs', context).forEach((element) => {
        function render() {
          loadCharts().then(() => {
            if (element.isConnected && element.parentElement) {
              Drupal.behaviors.chartsChartjs.attach(element.parentElement);
            }
          }).catch((error) => {
            console.error(error);
          });
        }
        if ('IntersectionObserver' in window) {
          const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
              observer.disconnect();
              render();
            }
          }, { rootMargin: '120px' });
          observer.observe(element);
        } else {
          render();
        }
      });
    },
  };
})(Drupal, once, drupalSettings);
