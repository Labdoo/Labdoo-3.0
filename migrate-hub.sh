#!/bin/bash

vendor/bin/drush entity:delete node --bundle=hub
vendor/bin/drush labdoo-sync hub
