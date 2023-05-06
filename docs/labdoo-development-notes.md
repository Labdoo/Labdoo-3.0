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

### Running the code chekers

Before committing code, please run the code checkers:

```
ddev ssh
./vendor/bin/phpcs --standard="Drupal,DrupalPractice" --extensions=php,module,inc,install,test,profile,theme,css,info,txt,md,yml web/modules/custom
phpstan --level=5 analyze web/modules/custom
```

### Exporting default content

We do this by using the default_content module. With this module enabled, 
create first the content you want to have by default and then use the following
command to export it:

```
ddev drush dce node 1 --file=modules/custom/lbd_content/node/100.json
```

where dce stands for 'default content export', node is the entity type that you 
are exporting, 1 is its ID, and the --file argument includes the path to the file
where you want to export the configuration to. Note that we store all the 
default content in modules/custom/lbd_content/, and that inside this folder
you need to follow the convention $entity_type_name/$node_id.json, were 
$entity_type_name is the name of the entity you are exporting (e.g., 'node')
and $node_id is the ID that you want it to have upon building a new site.
The content will be created at module initialization time.

### Building from a branch

To build Labdoo from a given branch, do as follows:

```
git checkout <branch>
ddev composer install
ddev drush updb
ddev drush cim
ddev drush cr
ddev drush cim
```

### Printing a message to the logger / watchdog

```
\Drupal::logger('labdoo_lib')->notice("Hey");
```



