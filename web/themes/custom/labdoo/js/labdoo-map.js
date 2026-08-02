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
          // We check multiple times to ensure we catch it.
          var checkCount = 0;
          var maxChecks = 10;
          var checkInterval = setInterval(function() {
            checkCount++;
            var map;
            
            // Try to find the map in various common locations
            if (el._leaflet_map) {
              map = el._leaflet_map;
            } else if (el.geolocationMap && el.geolocationMap.leafletMap) {
              map = el.geolocationMap.leafletMap;
            } else {
              // Geolocation might store it in data
              var geoData = $(el).data('geolocation-map');
              if (geoData && geoData.leafletMap) {
                map = geoData.leafletMap;
              } else {
                $container.find('*').each(function() {
                  if (this._leaflet_map) {
                    map = this._leaflet_map;
                    return false;
                  }
                  if (this.geolocationMap && this.geolocationMap.leafletMap) {
                    map = this.geolocationMap.leafletMap;
                    return false;
                  }
                  var childGeoData = $(this).data('geolocation-map');
                  if (childGeoData && childGeoData.leafletMap) {
                    map = childGeoData.leafletMap;
                    return false;
                  }
                });
              }
            }

            if (!map && checkCount === 1) {
              if (!$container.hasClass('leaflet-container')) {
                map = L.map(el).setView([20, 0], 2);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
              }
            }

            if (map) {
              console.log('Labdoo Map: Found map (check ' + checkCount + ')', map);
              
              // Ensure we have a reference on the element for future behaviors
              if (!el._leaflet_map) el._leaflet_map = map;

              // We need to re-enable interactions even if we already did, 
              // just in case another script disabled them in between.
              // But we only want to do the heavy setup (like wheel listeners) once.
              forceInteractions(map);
              
              if (!map._labdooInteractionsEnabled) {
                enableInteractions(map);
              }
              
              if (checkCount === 1) {
                loadAndDrawTrajectory(map, nid);
              }
              
              // We keep checking a few times even if found, because Geolocation might
              // re-initialize or override settings shortly after creation.
              if (checkCount >= maxChecks) {
                clearInterval(checkInterval);
              }
            } else if (checkCount >= maxChecks) {
              clearInterval(checkInterval);
              console.log('Labdoo Map: No map found after ' + maxChecks + ' checks');
            }
          }, 1000);
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

          var getPopupUrl = function(pointId) {
            return (type === 'labdooer' || type === 'user') ? '/user/' + pointId : '/node/' + pointId;
          };

          $.getJSON(apiUrl, function (data) {
            $.each(data, function (index, point) {
              if (point.lat && point.lon) {
                var marker = L.marker([point.lat, point.lon])
                  .bindPopup('<a href="' + getPopupUrl(point.id) + '">' + point.title + '</a>');
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
                       .bindPopup('<a href="' + getPopupUrl(point.id) + '">' + point.title + '</a>');
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
          if (map._labdooInteractionsEnabled) return;
          map._labdooInteractionsEnabled = true;

          console.log('Labdoo Map: Enabling interactions', map);

          forceInteractions(map);

          if (map.touchZoom) map.touchZoom.enable();
          if (map.doubleClickZoom) map.doubleClickZoom.enable();
          if (map.scrollWheelZoom) map.scrollWheelZoom.enable();
          if (map.boxZoom) map.boxZoom.enable();
          if (map.keyboard) map.keyboard.enable();
          if (map.tap) map.tap.enable();

          map.options.scrollWheelZoom = true;
          map.options.doubleClickZoom = true;
          map.options.touchZoom = true;
          map.options.boxZoom = true;
          map.options.keyboard = true;

          // Invalidate size once to ensure proper rendering
          setTimeout(function() {
            map.invalidateSize();
          }, 200);

          var container = map.getContainer();
          if (container) {
            container.style.pointerEvents = 'auto';
            $(container).find('.leaflet-map-pane').css('pointer-events', 'auto');
            $(container).find('.leaflet-overlay-pane').css('pointer-events', 'none');
            $(container).find('.leaflet-shadow-pane').css('pointer-events', 'none');
            $(container).find('.leaflet-marker-pane').css('pointer-events', 'auto');
            $(container).find('.leaflet-tile-pane').css('pointer-events', 'auto');
            $(container).find('.leaflet-objects-pane').css('pointer-events', 'none');
            $(container).find('svg.leaflet-zoom-animated').css('pointer-events', 'none');
            $(container).find('path.leaflet-interactive').css('pointer-events', 'none');
            $(container).find('.leaflet-gesture-handling-touch-overlay').css('pointer-events', 'none');
            $(container).find('.leaflet-gesture-handling-scroll-overlay').css('pointer-events', 'none');

            // Force interaction by adding event listeners to the container
            // to re-enable dragging if something disabled it
            $(container).on('mousedown touchstart', function() {
              forceInteractions(map);
            });

            // Add a capture-phase wheel listener so wheel events intercepted by child
            // elements (tile images, SVG panes) still trigger zoom.
            if (!container._labdooScrollZoom) {
              container._labdooScrollZoom = true;
              container.addEventListener('wheel', function(e) {
                // If the user is dragging, let's not interfere with zoom here if possible,
                // but usually wheel and dragging don't happen together.
                // We use setZoomAround to maintain the zoom functionality requested.
                e.preventDefault();
                var delta = e.deltaY > 0 ? -1 : 1;
                var containerPoint = map.mouseEventToContainerPoint(e);
                map.setZoomAround(containerPoint, map.getZoom() + delta);
              }, {capture: true, passive: false});
            }
          }
        }

        function forceInteractions(map) {
          var container = map.getContainer();
          
          if (map.dragging) {
            map.dragging.enable();
          }
          map.options.dragging = true;

          if (map.gestureHandling) {
            if (map.gestureHandling.enabled()) {
              map.gestureHandling.disable();
            }
          }
          
          // Geolocation specific override
          if (map.options.gestureHandling) {
            map.options.gestureHandling = false;
          }

          if (container) {
            $(container).find('.leaflet-gesture-handling-touch-overlay').css('pointer-events', 'none');
            $(container).find('.leaflet-gesture-handling-scroll-overlay').css('pointer-events', 'none');
            // Some versions use this class
            $(container).find('.leaflet-gesture-handling-overlay').css('pointer-events', 'none');
            $(container).find('.leaflet-overlay-pane').css('pointer-events', 'none');
            $(container).find('.leaflet-shadow-pane').css('pointer-events', 'none');
            $(container).find('svg.leaflet-zoom-animated').css('pointer-events', 'none');
            $(container).find('path.leaflet-interactive').css('pointer-events', 'none');
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
                  dashArray: '8, 12',
                  interactive: false
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

  Drupal.behaviors.labdooProximityOriginMap = {
    attach: function (context) {
      const exposedForms = once('labdoo-proximity-origin-map', '.views-exposed-form', context);

      exposedForms.forEach(function (formEl) {
        var $form = $(formEl);
        var $latInput = $form.find('input[name*="[source_configuration][origin][lat]"]');
        var $lonInput = $form.find('input[name*="[source_configuration][origin][lon]"]');

        if (!$latInput.length || !$lonInput.length || typeof L === 'undefined') {
          return;
        }

        var originContainer = $latInput.closest('.proximity-origin');
        if (!originContainer.length) {
          originContainer = $latInput.closest('.form-item').parent();
        }

        $latInput.prop('readonly', true).attr('aria-readonly', 'true');
        $lonInput.prop('readonly', true).attr('aria-readonly', 'true');
        $form.addClass('labdoo-proximity-origin-compact');

        if (originContainer.length) {
          originContainer.addClass('labdoo-proximity-origin-container');

          // Hide help text using robust selectors (Drupal markup varies by theme/module).
          originContainer.find('.description, .form-item__description, .fieldset__description').addClass('labdoo-proximity-origin-help-hidden');

          // Fallback: hide matching plain text description if classes differ.
          originContainer.find('div, p, span').filter(function () {
            return $(this).text().trim() === 'Value in decimal degrees. Use dot (.) as decimal separator.';
          }).addClass('labdoo-proximity-origin-help-hidden');

          // Mark the lat/lon wrappers regardless of exact wrapper class.
          $latInput.closest('.js-form-item, .form-item').addClass('labdoo-proximity-origin-item');
          $lonInput.closest('.js-form-item, .form-item').addClass('labdoo-proximity-origin-item');

          // Keep the origin title in one line.
          originContainer.find('.fieldset__legend, .fieldset-legend, legend, > label').addClass('labdoo-proximity-origin-nowrap');
        }

        var mapWrapper = document.createElement('div');
        mapWrapper.className = 'labdoo-proximity-origin-map-wrapper';

        var mapCanvas = document.createElement('div');
        mapCanvas.className = 'labdoo-proximity-origin-map';
        mapCanvas.style.height = '260px';
        mapCanvas.style.marginTop = '4px';
        mapCanvas.style.border = '1px solid #d9d9d9';
        mapCanvas.style.borderRadius = '4px';

        mapWrapper.appendChild(mapCanvas);

        if (originContainer.length) {
          originContainer.after(mapWrapper);
        }
        else {
          formEl.appendChild(mapWrapper);
        }

        bindMapToOriginInputs(mapCanvas, $form, $latInput, $lonInput);
      });

      function bindMapToOriginInputs(mapCanvas, $form, $latInput, $lonInput) {
        var defaultCenter = [20, 0];
        var defaultZoom = 2;
        var selectedZoom = 9;
        var map = L.map(mapCanvas).setView(defaultCenter, defaultZoom);
        var syncingInputs = false;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var originMarker;

        function toFixedCoord(value) {
          return Number(value).toFixed(6);
        }

        function isValidCoord(lat, lon) {
          return !isNaN(lat) && !isNaN(lon) && lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180;
        }

        function setOrigin(lat, lon, moveMap) {
          if (!isValidCoord(lat, lon)) {
            return;
          }

          var latValue = toFixedCoord(lat);
          var lonValue = toFixedCoord(lon);

          syncingInputs = true;
          $latInput.val(latValue);
          $lonInput.val(lonValue);
          syncingInputs = false;

          var summarySelector = '.proximity-origin-summary .geofield-lat-summary, .proximity-origin-summary .geofield-lon-summary';
          if ($form.find(summarySelector).length) {
            $form.find('.proximity-origin-summary .geofield-lat-summary').text(' ' + latValue);
            $form.find('.proximity-origin-summary .geofield-lon-summary').text(' ' + lonValue);
          }

          if (!originMarker) {
            originMarker = L.marker([lat, lon], {
              draggable: true,
              title: Drupal.t('Punto de origen')
            }).addTo(map);

            originMarker.on('dragend', function (event) {
              var markerPosition = event.target.getLatLng();
              setOrigin(markerPosition.lat, markerPosition.lng, false);
            });
          }
          else {
            originMarker.setLatLng([lat, lon]);
          }

          if (moveMap) {
            map.setView([lat, lon], Math.max(map.getZoom(), selectedZoom));
          }
        }

        function syncFromInputs() {
          if (syncingInputs) {
            return;
          }

          var inputLat = parseFloat($latInput.val());
          var inputLon = parseFloat($lonInput.val());
          if (isValidCoord(inputLat, inputLon)) {
            setOrigin(inputLat, inputLon, true);
          }
        }

        var initialLat = parseFloat($latInput.val());
        var initialLon = parseFloat($lonInput.val());
        if (isValidCoord(initialLat, initialLon)) {
          setOrigin(initialLat, initialLon, true);
        }

        map.on('click', function (event) {
          setOrigin(event.latlng.lat, event.latlng.lng, false);
        });

        $latInput.on('change input', syncFromInputs);
        $lonInput.on('change input', syncFromInputs);

        setTimeout(function () {
          map.invalidateSize();
        }, 100);
      }
    }
  };
})(jQuery, Drupal, once);
