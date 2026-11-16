#!/bin/bash

# Disable geocoding and search index
vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 1 -y
vendor/bin/drush cset search_api.index.default_index options.index_directly 0 -y

# Delete actions
vendor/bin/drush entity:delete node --bundle action

# Sync users
vendor/bin/drush entity:delete user
vendor/bin/drush labdoo-sync user

# Sync hubs
vendor/bin/drush entity:delete node --bundle=hub
vendor/bin/drush labdoo-sync hub

# Sync edoovillages
vendor/bin/drush entity:delete node --bundle=edoovillage
vendor/bin/drush labdoo-sync edoovillage

# Sync dootronics
vendor/bin/drush entity:delete node --bundle=dootronic
vendor/bin/drush labdoo-sync laptop

# Sync dootrips
vendor/bin/drush entity:delete node --bundle=dootrip
vendor/bin/drush labdoo-sync dootrip

# Sync teams
vendor/bin/drush entity:delete node --bundle=team_comment
vendor/bin/drush entity:delete node --bundle=team_post
vendor/bin/drush entity:delete node --bundle=team
vendor/bin/drush sql-query "DELETE FROM comment_entity_statistics WHERE entity_type='node' AND field_name='field_team_comments'"
vendor/bin/drush labdoo-sync-teams

# Enable geocoding and search index
vendor/bin/drush cset geocoder.settings geocoder_presave_disabled 0 -y
vendor/bin/drush cset search_api.index.default_index options.index_directly 1 -y
vendor/bin/drush search-api:index
