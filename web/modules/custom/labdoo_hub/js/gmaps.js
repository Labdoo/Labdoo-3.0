/* eslint-disable no-bitwise, no-nested-ternary, no-mutable-exports, comma-dangle, strict */

'use strict';

(($, Drupal, drupalSettings) => {
  Drupal.behaviors.gmaps = {
    attach: function attach() {

      /**
       * Renders the locations given by drupalSettings.
       *
       * @param mapSelector
       *   The map DOM selector.
       */
      function renderLocations(mapSelector) {
        if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
          return;
        }

        const coordinates = drupalSettings.labdoo_hub.data.locations;
        const map = new google.maps.Map(document.getElementsByClassName(mapSelector)[0], {
          zoom: 4,
          center: coordinates[0].coords,
        });
        const bounds = new google.maps.LatLngBounds();
        let markers = [];
        let infoWindow = new google.maps.InfoWindow();

        for (var i = 0; i < coordinates.length; i++) {
          markers[i] = new google.maps.Marker({
            position: coordinates[i].coords,
            map,
            // icon: {
            //   path: google.maps.SymbolPath.CIRCLE,
            //   fillColor: '#' + Math.floor(Math.random() *
            // 16777215).toString(16), fillOpacity: 1, strokeColor: '#000',
            // strokeWeight: 1, scale: 10 }
          });
          makeInfoWindowEvent(map, infoWindow, coordinates[i].title, markers[i]);
          bounds.extend(coordinates[i].coords);
        }

        map.fitBounds(bounds);
      }

      function makeInfoWindowEvent(map, infoWindow, contentString, marker) {
        google.maps.event.addListener(marker, 'click', function() {
          infoWindow.setContent(contentString);
          infoWindow.open(map, marker);
        });
      }

      $(document).ready(function () {
        let hasExecuted = false;
        const intervalID = setInterval(() => {
          if (!hasExecuted) {
            renderLocations("geofield-google-map");
            hasExecuted = true;
            clearInterval(intervalID);
          }
        }, 1000);
      });

    }
  }
})(jQuery, Drupal, drupalSettings);
