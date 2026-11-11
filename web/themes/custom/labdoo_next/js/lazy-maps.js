(function (Drupal, once, drupalSettings) {
  'use strict';

  const scripts = new Map();
  let standard;
  let behavior;
  function script(path) {
    if (!scripts.has(path)) {
      scripts.set(path, new Promise((resolve, reject) => {
        const element = document.createElement('script');
        element.src = drupalSettings.path.baseUrl + path;
        element.onload = resolve;
        element.onerror = () => {
          scripts.delete(path);
          reject(new Error('Unable to load map library: ' + path));
        };
        document.head.append(element);
      }));
    }
    return scripts.get(path);
  }
  function loadBehavior() {
    if (!behavior) {
      behavior = script(drupalSettings.labdooNext.mapBehavior).then(() => {
        Drupal.behaviors.labdooProximityOriginMap.attach(document);
      }).catch(error => {
        behavior = undefined;
        throw error;
      });
    }
    return behavior;
  }
  function loadStandard() {
    if (!standard) {
      standard = (window.L ? Promise.resolve() : script('themes/custom/labdoo_next/vendor/leaflet-1.0.3/dist/leaflet.js'))
        .then(() => window.L.markerClusterGroup ? undefined : script('themes/custom/labdoo_next/vendor/markercluster-1.5.3/dist/leaflet.markercluster.js'))
        .then(loadBehavior)
        .catch(error => {
          standard = undefined;
          throw error;
        });
    }
    return standard;
  }
  Drupal.behaviors.labdooNextMaps = {
    attach(context) {
      once('labdoo-next-map-loader', '.labdoo-map-container', context).forEach(element => {
        function initialize() {
          const native = element.querySelector('.geolocation-map-wrapper');
          // Native Geolocation maps load their own provider and Leaflet version.
          // Wait for it rather than loading a second Leaflet implementation.
          const ready = native
            ? Drupal.geolocation.maps.getMap(native.id).then(loadBehavior)
            : loadStandard();
          ready.then(() => {
            if (element.isConnected) Drupal.behaviors.labdooMap.attach(element, drupalSettings);
          }).catch(error => console.error(error));
        }
        if ('IntersectionObserver' in window) {
          const observer = new IntersectionObserver(entries => {
            if (entries.some(entry => entry.isIntersecting)) {
              observer.disconnect();
              initialize();
            }
          });
          observer.observe(element);
        } else {
          initialize();
        }
      });
    },
  };
})(Drupal, once, drupalSettings);
