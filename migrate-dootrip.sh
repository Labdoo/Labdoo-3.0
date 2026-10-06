#!/bin/bash

vendor/bin/drush entity:delete node --bundle=dootrip
vendor/bin/drush labdoo-sync dootrip
