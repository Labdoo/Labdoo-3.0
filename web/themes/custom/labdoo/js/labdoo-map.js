(function ($, Drupal, once) {
  Drupal.behaviors.labdooMap = {
    attach: function (context, settings) {
      const elements = once('labdoo-map', '.labdoo-map-container', context);
      
      elements.forEach(function (el) {
        var $container = $(el);
        var type = $container.data('labdoo-map-type');

        if (typeof L === 'undefined') {
          console.error('Leaflet is not loaded.');
          return;
        }

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

              // Draw trajectory if available.
              if (point.trajectory && point.trajectory.length > 1) {
                var latlngs = [];
                $.each(point.trajectory, function(i, loc) {
                  if (loc.lat && loc.lon) {
                    latlngs.push([loc.lat, loc.lon]);
                  }
                });
                // Also add current location to the end of trajectory if not already there.
                if (latlngs.length > 0 && (latlngs[latlngs.length-1][0] !== point.lat || latlngs[latlngs.length-1][1] !== point.lon)) {
                  latlngs.push([point.lat, point.lon]);
                }
                
                if (latlngs.length > 1) {
                  var polyline = L.polyline(latlngs, {
                    color: '#00aeee', // Labdoo blue
                    weight: 2,
                    opacity: 0.6,
                    dashArray: '5, 5'
                  }).addTo(map);
                }
              }
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
          
          // Ensure map is correctly rendered
          setTimeout(function() {
            map.invalidateSize();
          }, 200);
        }).fail(function() {
          // Retry with /en/ prefix if it fails (just in case)
          if (apiUrl.indexOf('/en/') === -1) {
            $.getJSON('/en' + apiUrl, function(data) {
               // Same logic as above (simplified for brevity)
               $.each(data, function (index, point) {
                 if (point.lat && point.lon) {
                   var marker = L.marker([point.lat, point.lon])
                     .bindPopup('<a href="/node/' + point.id + '">' + point.title + '</a>');
                   markers.addLayer(marker);
                 }
               });
               map.addLayer(markers);
               if (data.length > 0) { map.fitBounds(markers.getBounds()); }
               setTimeout(function() { map.invalidateSize(); }, 200);
            });
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
