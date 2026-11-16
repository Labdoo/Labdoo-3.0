#!/bin/bash
# Disable search index
vendor/bin/drush cset search_api.index.default_index options.index_directly 0 -y

vendor/bin/drush entity:delete node --bundle=basic_page
vendor/bin/drush labdoo-sync-basic-pages

# Enable search index and reindex
vendor/bin/drush cset search_api.index.default_index options.index_directly 1 -y
vendor/bin/drush search-api:index
