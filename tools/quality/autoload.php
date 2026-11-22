<?php

/**
 * @file
 * Loads isolated tooling and the unchanged Drupal runtime dependencies.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
// geoPHP loads its Geometry classes from its Composer-mapped entry point.
class_exists(\geoPHP::class);
return $loader;
