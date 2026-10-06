#!/bin/bash

vendor/bin/drush entity:delete node --bundle=dootronic
vendor/bin/drush labdoo-sync laptop
