# Install empty Drupal site with user 1 as admin/admin
ddev drush si -y --account-pass=admin --site-name='Labdoo3.0' standard
# Do a drush configuration import twice in case first import enables additional configuration (e.g. config_ignore or config_split)
ddev drush cim -y
ddev drush cim -y
ddev drush cr
