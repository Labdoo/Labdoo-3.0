/**
 * @file
 * Adds Google Maps auth referrer policy for better cross-browser compatibility.
 */

(function ($, Drupal) {
  'use strict';

  if (!Drupal.geoFieldMapFormatter
    || typeof Drupal.geoFieldMapFormatter.loadGoogle !== 'function'
    || Drupal.geoFieldMapFormatter.labdooAuthReferrerPolicyPatched) {
    return;
  }

  Drupal.geoFieldMapFormatter.loadGoogle = function (mapid, gmap_api_key, additional_libraries, callback) {
    const self = this;
    const html_language = $('html').attr('lang') || 'en';

    // Add the callback.
    self.addCallback(callback);

    // Check for Google Maps.
    if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
      if (self.maps_api_loading === true) {
        return;
      }

      self.maps_api_loading = true;

      // Google Maps isn't loaded so lazy load Google Maps.
      let scriptPath = self.map_data[mapid]['gmap_api_localization']
        + '?v=weekly&sensor=false&language='
        + self.googleMapsLanguage(html_language)
        + '&callback=Drupal.geoFieldMapFormatter.googleCallback&loading=async';

      // If a Google API key is set, use it.
      if (gmap_api_key) {
        scriptPath += '&key=' + gmap_api_key;

        if (!scriptPath.includes('auth_referrer_policy=')) {
          scriptPath += '&auth_referrer_policy=origin';
        }
      }

      if (additional_libraries) {
        const libraries = [];
        for (const library in additional_libraries) {
          if (Object.prototype.hasOwnProperty.call(additional_libraries, library)) {
            libraries.push(library);
          }
        }
        scriptPath += '&libraries=' + libraries.join();
      }

      $.getScript(scriptPath)
        .done(function () {
          self.maps_api_loading = false;
        });
    }
    else {
      // Google Maps loaded. Run callback.
      self.googleCallback();
    }

  };

  Drupal.geoFieldMapFormatter.labdooAuthReferrerPolicyPatched = true;
})(jQuery, Drupal);
