#!/bin/bash

vendor/bin/drush entity:delete node --bundle=team_comment
vendor/bin/drush entity:delete node --bundle=team_post
vendor/bin/drush entity:delete node --bundle=team

# Clean up stale comment statistics for team posts to avoid duplicate key errors
vendor/bin/drush sql-query "DELETE FROM comment_entity_statistics WHERE entity_type='node' AND field_name='field_team_comments'"

vendor/bin/drush labdoo-sync-teams

