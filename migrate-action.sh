#!/bin/bash

vendor/bin/drush entity:delete node --bundle=action
vendor/bin/drush labdoo-sync action
