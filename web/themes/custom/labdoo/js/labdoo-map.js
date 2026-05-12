(function ($, Drupal, once) {
  Drupal.behaviors.labdooMap = {
    attach: function (context, settings) {
      const elements = once('labdoo-map', '.labdoo-map-container', context);
      
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
          // We wait a bit to ensure Leaflet has done its work.
          setTimeout(function() {
            var map;
            
            // Try to find a map object attached to the element (common in Leaflet modules)
            if (el._leaflet_map) {
              map = el._leaflet_map;
            } else {
              // Look in child elements if the container itself doesn't have it
              $container.find('*').each(function() {
                if (this._leaflet_map) {
                  map = this._leaflet_map;
                  return false;
                }
              });
            }

            if (!map) {
              // If no map found, initialize a new one (as fallback)
              // But only if it's not already initialized.
              if ($container.hasClass('leaflet-container')) {
                 // It IS a map, but we couldn't get the object easily.
                 // This is tricky. Let's try to initialize if empty.
              } else {
                 map = L.map(el).setView([20, 0], 2);
                 L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                   attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                 }).addTo(map);
              }
            }

            if (map) {
              loadAndDrawTrajectory(map, nid);
            }
          }, 1000);
        }
        else {
          // Standard map with all points of a type
          // Initialize map
          var map = L.map(el).setView([20, 0], 2);

          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
          }).addTo(map);

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
                map.fitBounds(markers.getBounds());
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
                 if (data.length > 0) { map.fitBounds(markers.getBounds()); }
              });
            }
          });
        }

        function loadAndDrawTrajectory(map, nid) {
          $.getJSON('/api/node-trajectory/' + nid, function (trajectory) {
            if (trajectory.length > 0) {
              var latlngs = [];
              var trajectoryMarkers = L.featureGroup();
              
              $.each(trajectory, function (i, point) {
                latlngs.push([point.lat, point.lon]);
                
                // Add markers for each point in trajectory if it's a detail map
                var marker = L.marker([point.lat, point.lon]);
                trajectoryMarkers.addLayer(marker);
              });
              
              if (latlngs.length > 1) {
                L.polyline(latlngs, {
                  color: '#00aeee', // Labdoo blue
                  weight: 3,
                  opacity: 0.7,
                  dashArray: '5, 10'
                }).addTo(map);
              }
              
              trajectoryMarkers.addTo(map);
              map.fitBounds(trajectoryMarkers.getBounds());
            }
          });
        }
        
        // Ensure map is correctly rendered
        setTimeout(function() {
          map.invalidateSize();
        }, 200);
      });
    }
  };
})(jQuery, Drupal, once);
