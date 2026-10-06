#!/bin/bash

vendor/bin/drush entity:delete node --bundle=labdoo_story
vendor/bin/drush labdoo-sync-stories
