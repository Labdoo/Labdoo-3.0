<?php

/**
 * @file
 * Read-only integration checks for the geographic dependency upgrade.
 *
 * Run with: drush php:script tests/php/geofield-upgrade-regression.php.
 */

use Drupal\labdoo_migrate\Services\SpecialFieldTypes\SpecialFieldTypeGeolocation;

$id = \Drupal::database()->query('SELECT n.nid FROM {node} n INNER JOIN {node_field_data} d ON n.nid=d.nid AND d.default_langcode=1 ORDER BY n.nid LIMIT 1')->fetchField();
$entity = $id ? \Drupal::entityTypeManager()->getStorage('node')->load($id) : NULL;
if (!$entity) {
  throw new RuntimeException('An existing node is required; this test creates no content.');
}
$generator = \Drupal::service('geofield.wkt_generator');
$processor = new SpecialFieldTypeGeolocation($generator);
$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

$wkt = $processor->getValue('40.4168,-3.7038', [], $entity, 'en');
$point = geoPHP::load($wkt, 'wkt');
$check($point->geometryType() === 'Point', 'Migrated coordinates must remain a point.');
$check(abs($point->getX() + 3.7038) < 0.000000001 && abs($point->getY() - 40.4168) < 0.000000001, 'The latitude/longitude order must be preserved.');

$points = $processor->getValue(['40.4168,-3.7038', '48.8566,2.3522'], [], $entity, 'en');
$second = geoPHP::load($points[1], 'wkt');
$check(count($points) === 2 && abs($second->getX() - 2.3522) < 0.000000001 && abs($second->getY() - 48.8566) < 0.000000001, 'Multiple migrated locations must retain their coordinates.');
$check($processor->getValue('91,181', [], $entity, 'en') === NULL, 'Invalid coordinates must remain rejected.');

$coordinates = [
  [-3.7038, 40.4168],
  [2.3522, 48.8566],
];
$line = geoPHP::load($generator->wktBuildLinestring($coordinates), 'wkt');
$check($line->geometryType() === 'LineString' && count($line->getComponents()) === 2, 'Trip line generation must remain compatible.');

echo "Geofield upgrade integration: 5 assertions passed; no content saved.\n";
