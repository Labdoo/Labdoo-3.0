/* eslint-disable max-nested-callbacks,func-names */
/**
 * @file
 * Secure Javascript for the Geocoder Origin Autocomplete.
 */

(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.geocode_origin_autocomplete = {
    attach(context, settings) {
      function getGeocodePath() {
        const rawPrefix = drupalSettings.path.pathPrefix || "";
        const prefix = rawPrefix.replace(/^\/+|\/+$/g, "");
        return prefix === ""
          ? "/geocoder/api/geocode"
          : `/${prefix}/geocoder/api/geocode`;
      }

      function geocode(address, providers, addressFormat) {
        const geocodePath = getGeocodePath();
        const addressFormatQueryUrl =
          addressFormat === null ? "" : `&address_format=${addressFormat}`;
        return $.ajax({
          url: `${geocodePath}?address=${encodeURIComponent(
            address,
          )}&geocoder=${providers}${addressFormatQueryUrl}`,
          type: "GET",
          contentType: "application/json; charset=utf-8",
          dataType: "json",
        });
      }

      once(
        "autocomplete-enabled",
        ".origin-address-autocomplete .address-input",
        context,
      ).forEach(function (element) {
        const providers =
          settings.geocode_origin_autocomplete.providers.toString();
        const { address_format: addressFormat } =
          settings.geocode_origin_autocomplete;
        $(element)
          .autocomplete({
            autoFocus: true,
            minLength: settings.geocode_origin_autocomplete.minTerms || 4,
            delay: settings.geocode_origin_autocomplete.delay || 800,
            source(request, response) {
              const thisElement = this.element;
              thisElement.addClass("ui-autocomplete-loading");
              $.when(
                geocode(request.term, providers, addressFormat).then(
                  function (results) {
                    response(
                      $.map(results, function (item) {
                        thisElement.removeClass("ui-autocomplete-loading");
                        return {
                          value: item.formatted_address,
                        };
                      }),
                    );
                  },
                  function () {
                    response(function () {
                      return false;
                    });
                  },
                ),
              );
            },
          })
          .addClass("form-autocomplete");
      });
    },
  };
})(jQuery, Drupal, drupalSettings);
