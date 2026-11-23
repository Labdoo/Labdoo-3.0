(function (Drupal, once, drupalSettings) {
  'use strict';

  const scripts = new Map();
  let bridge;
  let renderCharts;
  function script(path) {
    if (!scripts.has(path)) {
      scripts.set(path, new Promise((resolve, reject) => {
        const element = document.createElement('script');
        element.src = drupalSettings.path.baseUrl + (drupalSettings.labdooNext.staticAssets?.[path] || path);
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
  const vendor = 'themes/custom/labdoo_next/vendor/';
  let charts;
  let fullCharts;
  let fullLoaded = false;
  function chartLibrary(full) {
    if (!charts) {
      charts = script(vendor + 'chart.js-4.4.0/dist/' + (full ? 'chart.umd.js' : 'chart-bar.js'))
        .then(() => { fullLoaded = full; })
        .catch(error => { charts = undefined; throw error; });
    }
    if (!full) return fullCharts || charts;
    if (!fullCharts) {
      fullCharts = charts.then(() => {
        if (fullLoaded) return;
        const previous = window.Chart;
        return script(vendor + 'chart.js-4.4.0/dist/chart.umd.js').then(() => {
          // Preserve existing bar charts when a later AJAX chart needs more
          // controllers or scales from the full, same-version library.
          const ids = Object.keys(previous.instances).map(Number);
          if (ids.length) {
            // Each bundle starts its own ID counter. Reserve existing IDs
            // before creating full-library charts, so none replace old charts.
            while (window.Chart.helpers.uid() <= Math.max(...ids)) { /* Reserve. */ }
          }
          const instances = window.Chart.instances;
          Object.entries(previous.instances).forEach(([id, instance]) => {
            instances[id] = instance;
            const notify = instance.notifyPlugins;
            instance.notifyPlugins = function (hook, ...args) {
              // Each bundle owns a private registry. Remove the old instance
              // from both before afterDestroy callbacks observe the registry.
              if (hook === 'afterDestroy') delete instances[id];
              return notify.call(this, hook, ...args);
            };
          });
          fullLoaded = true;
        });
      }).catch(error => { fullCharts = undefined; throw error; });
    }
    return fullCharts;
  }
  function loadCharts(parent) {
    const base = 'themes/custom/labdoo_next/vendor/';
    const configs = Array.from(parent.querySelectorAll('div.charts-chartjs'), element => JSON.parse(element.dataset.chart || '{}'));
    const full = configs.some(config => config.type !== 'bar'
      || config.data?.datasets?.some(dataset => dataset.type && dataset.type !== 'bar')
      || Object.values(config.options?.scales || {}).some(scale => scale.type && !['category', 'linear'].includes(scale.type))
      || config.options?.plugins?.datalabels || config.options?.plugins?.dataLabels || config.options?.plugins?.annotations);
    const library = chartLibrary(full);
    const requests = [library];
    if (configs.some(config => Object.values(config.options?.scales || {}).some(scale => ['time', 'timeseries'].includes(scale.type)))) {
      requests.push(library.then(() => script(base + 'chartjs-adapter-date-fns-3.0.0/dist/chartjs-adapter-date-fns.bundle.js')));
    }
    if (configs.some(config => Boolean(config.options?.plugins?.datalabels))) {
      requests.push(library.then(() => script(base + 'chartjs-plugin-datalabels-2.0.0/dist/chartjs-plugin-datalabels.js')));
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
        const aspectRatio = String(options.aspectRatio || (square ? 1 : 2));
        if (options.responsive !== false && options.maintainAspectRatio !== false) {
          // Legends wrap on narrow screens. Give the plot a minimum height
          // instead of letting a width-derived canvas height consume all of it.
          const frame = document.createElement('div');
          frame.className = 'ldn-chart-frame';
          frame.style.aspectRatio = aspectRatio;
          element.before(frame);
          frame.append(element);
          options.maintainAspectRatio = false;
          config.options = options;
          element.dataset.chart = JSON.stringify(config);
        } else {
          element.style.aspectRatio = aspectRatio;
        }
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
