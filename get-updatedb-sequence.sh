#!/bin/bash

vendor/drush/drush/drush ev "print \Drupal::service('update.update_hook_registry')->getInstalledVersion('$1');"
echo ""
