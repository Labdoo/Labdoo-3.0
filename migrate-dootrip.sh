#!/bin/bash

# Disable geocoding and search index
vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
vendor/bin/drush cset search_api.index.default_index options.index_directly 0 -y

#vendor/bin/drush entity:delete node --bundle=dootrip
vendor/bin/drush labdoo-sync dootrip

# Enable geocoding and search index
vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y
vendor/bin/drush cset search_api.index.default_index options.index_directly 1 -y
vendor/bin/drush search-api:index
