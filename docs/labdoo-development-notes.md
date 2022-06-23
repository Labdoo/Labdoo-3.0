## Creation of the core Labdoo Objects

The core Labdoo objects are: user, dootronic, hub, dootrip, edoovillage. 
In this Section we provide notes on how we created these objects in Labdoo 3.0.

See: https://docs.google.com/drawings/d/1SPkDiNhb7HN3mjrW1XGnobdJ1Hvclex-tEs8dkMqcj0/edit?usp=sharing

### Basic export / import commands

- To export the configuration from local to config/default: `ddev drush config:export`

- To import the configuration from config/default to local: `ddev drush config:import`

- To list the set of changes in local: `ddev drush config:status` 

### Location field

The D7 location module is replaced in D8/D9 with the geolocation and address modules.
To install and enable these two modules:

```
ddev composer require drupal/geolocation
ddev composer require drupal/address
ddev drush pm-enable geolocation
ddev drush pm-enable address

ddev composer require drupal/geocoder
ddev composer require drupal/geocoder_field
ddev composer require drupal/geocoder_address

ddev drush pm-enable geocoder geocoder_field geocoder_address
```

Install the Google Maps Provider:

```
ddev composer require geocoder-php/google-maps-provider
ddev drush cache-rebuild
```

Remember to enable Google Maps API and Google Places modules.

### Conditional fields

Install conditional fields module:

```
ddev composer require drupal/conditional_fields 
ddev drush pm-enable conditional_fields
```

### Bringing a branch up-to-date with the main branch

```
git checkout branch_name
git merge main
```
