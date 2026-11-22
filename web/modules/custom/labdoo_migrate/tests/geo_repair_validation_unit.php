<?php

/**
 * @file
 * Checks geography repair validation using the real Drupal and Drush classes.
 */

declare(strict_types=1);

$qualityRoot = dirname(__DIR__, 5);
$loader = require $qualityRoot . '/tools/quality/autoload.php';
$loader->addPsr4('Drupal\\labdoo_migrate\\', $qualityRoot . '/web/modules/custom/labdoo_migrate/src');
$loader->addPsr4('Drupal\\node\\', $qualityRoot . '/web/core/modules/node/src');
use Drupal\labdoo_migrate\Commands\GeographySynchronizerCommands;

$reflection = new \ReflectionClass(GeographySynchronizerCommands::class);
$command = $reflection->newInstanceWithoutConstructor();
$validate = $reflection->getMethod('getGeoInvalidReason');
$validate->setAccessible(TRUE);
$cases = [
  'valid coordinates' => [51.5, -0.12, '51.500000,-0.120000', NULL],
  'zero sentinel' => [0, 0, '0.000000,0.000000', 'sentinela 0,0'],
  'null coordinates' => [NULL, NULL, ',', 'lat/lon NULL'],
  'out of range' => [91, 0, '91.000000,0.000000', 'fuera de rango'],
  'empty value' => [51.5, -0.12, '', 'valor vacío/placeholder'],
];

foreach ($cases as $label => [$lat, $lon, $value, $expected]) {
  $actual = $validate->invoke($command, $lat, $lon, $value);
  if ($actual !== $expected) {
    throw new \RuntimeException(sprintf('%s: expected %s, got %s', $label, var_export($expected, TRUE), var_export($actual, TRUE)));
  }
  echo "PASS: {$label}\n";
}
