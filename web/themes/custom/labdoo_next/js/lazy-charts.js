(function (Drupal, once, drupalSettings) {
  'use strict';

  const scripts = new Map();
  let bridge;
  let renderCharts;
  function script(path) {
    if (!scripts.has(path)) {
      scripts.set(path, new Promise((resolve, reject) => {
        const element = document.createElement('script');
        element.src = drupalSettings.path.baseUrl + path;
        // Fetch together while preserving dependency execution order.
        element.async = false;
        element.onload = resolve;
        element.onerror = () => {
          scripts.delete(path);
          reject(new Error('Unable to load chart library: ' + path));
        };
        document.head.append(element);
      }));
    }
    return scripts.get(path);
  }
  function loadCharts(parent) {
    const base = 'themes/custom/labdoo_next/vendor/';
    const configs = Array.from(parent.querySelectorAll('div.charts-chartjs'), element => JSON.parse(element.dataset.chart || '{}'));
    const requests = [script(base + 'chart.js-4.4.0/dist/chart.umd.js')];
    if (configs.some(config => Object.values(config.options?.scales || {}).some(scale => ['time', 'timeseries'].includes(scale.type)))) {
      requests.push(script(base + 'chartjs-adapter-date-fns-3.0.0/dist/chartjs-adapter-date-fns.bundle.js'));
    }
    if (configs.some(config => Boolean(config.options?.plugins?.datalabels))) {
      requests.push(script(base + 'chartjs-plugin-datalabels-2.0.0/dist/chartjs-plugin-datalabels.js'));
    }
    if (!bridge) {
      bridge = script(drupalSettings.labdooNext.chartsBridge.replace(/^\//, '')).then(() => {
        renderCharts = Drupal.behaviors.chartsChartjs.attach.bind(Drupal.behaviors.chartsChartjs);
        // Future AJAX attachments must also wait for their optional plug-ins.
        Drupal.behaviors.chartsChartjs.attach = context => Drupal.behaviors.labdooNextCharts.attach(context);
      }).catch(error => {
        bridge = undefined;
        throw error;
      });
    }
    requests.push(bridge);
    return Promise.all(requests);
  }

  Drupal.behaviors.labdooNextCharts = {
    attach(context) {
      once('labdoo-next-chart', '.charts-chartjs', context).forEach((element) => {
        const config = JSON.parse(element.dataset.chart || '{}');
        const options = config.options || {};
        // Draw data immediately rather than animating the first viewport for a
        // second. Preserve an explicitly configured animation on custom charts.
        if (options.animation === undefined) {
          options.animation = false;
          config.options = options;
          element.dataset.chart = JSON.stringify(config);
        }
        const square = ['pie', 'doughnut', 'polarArea', 'radar'].includes(config.type);
        element.style.aspectRatio = String(options.aspectRatio || (square ? 1 : 2));
        function render() {
          if (!element.isConnected || !element.parentElement) return;
          loadCharts(element.parentElement).then(() => {
            if (element.isConnected && element.parentElement) {
              renderCharts(element.parentElement);
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
          }, { rootMargin: '0px' });
          observer.observe(element);
        } else {
          render();
        }
      });
    },
  };
})(Drupal, once, drupalSettings);
