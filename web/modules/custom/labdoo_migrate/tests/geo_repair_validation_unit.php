<?php

declare(strict_types=1);

namespace Drush\Commands {
  class DrushCommands {
    public function __construct() {}
  }
}

namespace Drupal\node {
  interface NodeInterface {}
}

namespace {
  use Drupal\labdoo_migrate\Commands\GeographySynchronizerCommands;

  require dirname(__DIR__) . '/src/Commands/GeographySynchronizerCommands.php';

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
}
