#!/bin/bash

vendor/bin/drush entity:delete node --bundle=basic_page
vendor/bin/drush labdoo-sync-basic-pages
