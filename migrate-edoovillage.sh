#!/bin/bash

vendor/bin/drush entity:delete node --bundle=edoovillage
vendor/bin/drush labdoo-sync edoovillage
