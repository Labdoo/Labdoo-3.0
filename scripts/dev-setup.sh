# Start ddev
ddev start -y
# Install dependencies, which includes drush
ddev composer install
# Install empty Drupal site with user 1 as admin/admin
ddev drush site:install -y --account-pass=admin --site-name='Labdoo3.0' minimal
# Force the new site UUID to be the same as the configuration
ddev drush config:set 'system.site' uuid 'e5f3dd3c-79fa-4957-84db-90fc98effac5' -y
# Install any Drupal database updates
ddev drush updatedb -y
# Do a drush configuration import twice in case first import enables additional configuration (e.g. config_ignore or config_split)
ddev drush config:import -y
ddev drush config:import -y
ddev drush cache:rebuild
