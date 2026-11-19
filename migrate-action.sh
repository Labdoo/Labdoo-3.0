#!/bin/bash
# Disable search index
vendor/bin/drush cset search_api.index.default_index options.index_directly 0 -y

#vendor/bin/drush entity:delete node --bundle=action
vendor/bin/drush labdoo-sync action

# Enable search index and reindex
vendor/bin/drush cset search_api.index.default_index options.index_directly 1 -y
vendor/bin/drush search-api:index
