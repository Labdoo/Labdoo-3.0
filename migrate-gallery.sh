#!/bin/bash

vendor/bin/drush entity:delete node --bundle=gallery
vendor/bin/drush labdoo-sync-galleries
