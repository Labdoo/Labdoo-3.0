(function ($, Drupal, once) {
  Drupal.behaviors.labdooMap = {
    attach: function (context, settings) {
      const elements = once('labdoo-map', '.labdoo-map-container, .geolocation-map-container', context);

      elements.forEach(function (el) {
        var $container = $(el);
        var type = $container.data('labdoo-map-type');
        var nid = $container.data('nid');

        if (typeof L === 'undefined') {
          console.error('Leaflet is not loaded.');
          return;
        }

        // If NID is present, we look for an existing Leaflet map on this element
        // or its children, or we initialize a new one.
        if (nid) {
          // Geolocation module might have already initialized a map.
          // We check multiple times to ensure we catch it.
          for (let i = 1; i <= 5; i++) {
            setTimeout(function() {
              var map;
              if (el._leaflet_map) {
                map = el._leaflet_map;
              } else {
                $container.find('*').each(function() {
                  if (this._leaflet_map) {
                    map = this._leaflet_map;
                    return false;
                  }
                });
              }

              if (!map && i === 1) {
                if (!$container.hasClass('leaflet-container')) {
                  map = L.map(el).setView([20, 0], 2);
                  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                  }).addTo(map);
                }
              }

              if (map) {
                console.log('Labdoo Map: Found map after ' + i + 's', map);
                enableInteractions(map);
                if (i === 1) loadAndDrawTrajectory(map, nid);
                setTimeout(function() {
                  map.invalidateSize();
                }, 200);
              }
            }, i * 1000);
          }
        }
        else {
          // Standard map with all points of a type
          // Initialize map
          var map = L.map(el).setView([20, 0], 2);

          enableInteractions(map);

          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
          }).addTo(map);

          // Ensure map is correctly rendered
          setTimeout(function() {
            map.invalidateSize();
          }, 200);

          var markers = L.markerClusterGroup();

          // Use absolute path for API
          var apiUrl = '/api/map-points/' + type;

          $.getJSON(apiUrl, function (data) {
            $.each(data, function (index, point) {
              if (point.lat && point.lon) {
                var marker = L.marker([point.lat, point.lon])
                  .bindPopup('<a href="/node/' + point.id + '">' + point.title + '</a>');
                markers.addLayer(marker);
              }
            });
            map.addLayer(markers);

            if (data.length > 0) {
              try {
                map.fitBounds(markers.getBounds(), {padding: [50, 50], maxZoom: 15});
              } catch (e) {
                console.error('Error fitting bounds:', e);
              }
            }
          }).fail(function() {
            // Retry with /en/ prefix if it fails (just in case)
            if (apiUrl.indexOf('/en/') === -1) {
              $.getJSON('/en' + apiUrl, function(data) {
                 $.each(data, function (index, point) {
                   if (point.lat && point.lon) {
                     var marker = L.marker([point.lat, point.lon])
                       .bindPopup('<a href="/node/' + point.id + '">' + point.title + '</a>');
                     markers.addLayer(marker);
                   }
                 });
                 map.addLayer(markers);
                 if (data.length > 0) { map.fitBounds(markers.getBounds(), {padding: [50, 50], maxZoom: 15}); }
              });
            }
          });
        }

        function enableInteractions(map) {
          console.log('Labdoo Map: Enabling interactions', map);
          
          if (map.dragging) map.dragging.enable();
          if (map.touchZoom) map.touchZoom.enable();
          if (map.doubleClickZoom) map.doubleClickZoom.enable();
          if (map.scrollWheelZoom) map.scrollWheelZoom.enable();
          if (map.boxZoom) map.boxZoom.enable();
          if (map.keyboard) map.keyboard.enable();
          if (map.tap) map.tap.enable();

          // Force options in the options object
          map.options.dragging = true;
          map.options.scrollWheelZoom = true;
          map.options.doubleClickZoom = true;
          map.options.touchZoom = true;
          map.options.boxZoom = true;
          map.options.keyboard = true;
          
          if (map.gestureHandling) {
            map.gestureHandling.enable();
          }

          // Force pointer events on the container
          var container = map.getContainer();
          if (container) {
            container.style.pointerEvents = 'auto';
            $(container).find('.leaflet-overlay-pane').css('pointer-events', 'none');
            $(container).find('.leaflet-marker-pane').css('pointer-events', 'auto');
            $(container).find('.leaflet-tile-pane').css('pointer-events', 'auto');
            
            // If there's a gesture handling overlay, make sure it doesn't block everything incorrectly
            $(container).find('.leaflet-gesture-handling-touch-overlay').css('pointer-events', 'none');
          }
        }

        function loadAndDrawTrajectory(map, nid) {
          $.getJSON('/api/node-trajectory/' + nid, function (trajectory) {
            if (trajectory.length > 0) {
              var latlngs = [];
              var trajectoryMarkers = L.featureGroup();

              $.each(trajectory, function (i, point) {
                latlngs.push([point.lat, point.lon]);

                // Add markers for each point in trajectory if it's a detail map
                var icon = L.divIcon({
                  className: 'labdoo-map-numbered-marker',
                  html: '<span>' + (point.index || (i + 1)) + '</span>',
                  iconSize: [26, 26],
                  iconAnchor: [13, 13]
                });

                var popupContent = '<strong>' + Drupal.t('Point') + ' ' + (point.index || (i + 1)) + '</strong>';
                if (point.date) {
                  popupContent += '<br>' + Drupal.t('Date') + ': ' + point.date;
                }

                var marker = L.marker([point.lat, point.lon], {icon: icon})
                  .bindPopup(popupContent);
                trajectoryMarkers.addLayer(marker);
              });

              if (latlngs.length > 1) {
                L.polyline(latlngs, {
                  color: '#00aeee', // Labdoo blue
                  weight: 4,
                  opacity: 0.8,
                  dashArray: '8, 12'
                }).addTo(map);
              }

              trajectoryMarkers.addTo(map);
              map.fitBounds(trajectoryMarkers.getBounds(), {padding: [50, 50], maxZoom: 15});
            }
          });
        }
      });
    }
  };
})(jQuery, Drupal, once);
