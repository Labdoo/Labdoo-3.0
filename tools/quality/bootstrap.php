<?php

/**
 * @file
 * Registers Drupal unit-test namespaces without bootstrapping a site.
 */

declare(strict_types=1);

require __DIR__ . '/autoload.php';
if (!defined('PHPUNIT_COMPOSER_INSTALL')) {
  define('PHPUNIT_COMPOSER_INSTALL', __DIR__ . '/autoload.php');
}
require dirname(__DIR__, 2) . '/web/core/tests/bootstrap.php';
// The existing chart unit tests exercise a procedural module helper.
require_once dirname(__DIR__, 2) . '/web/modules/custom/labdoo_edoovillage/labdoo_edoovillage.module';
