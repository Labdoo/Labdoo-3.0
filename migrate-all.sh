#!/bin/bash

# Delete actions
vendor/bin/drush entity:delete node --bundle=action

# Sync users
vendor/bin/drush entity:delete user
vendor/bin/drush labdoo-sync user

# Sync hubs
vendor/bin/drush entity:delete node --bundle=hub
vendor/bin/drush labdoo-sync hub --limit=100

# Sync edoovillages
vendor/bin/drush entity:delete node --bundle=edoovillage
vendor/bin/drush labdoo-sync edoovillage --limit=100

# Sync dootronics
vendor/bin/drush entity:delete node --bundle=dootronic
vendor/bin/drush labdoo-sync laptop --limit=100

# Sync dootrips
vendor/bin/drush entity:delete node --bundle=dootrip
vendor/bin/drush labdoo-sync dootrip --limit=100

# Sync teams
vendor/bin/drush entity:delete node --bundle=team_comment
vendor/bin/drush entity:delete node --bundle=team_post
vendor/bin/drush entity:delete node --bundle=team
vendor/bin/drush sql-query "DELETE FROM comment_entity_statistics WHERE entity_type='node' AND field_name='field_team_comments'"
vendor/bin/drush labdoo-sync-teams

