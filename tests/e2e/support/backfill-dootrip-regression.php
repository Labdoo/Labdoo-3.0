<?php

require __DIR__ . '/assert-isolated.php';

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\labdoo_migrate\Commands\DootripLocationTextCommands;
use Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$state = Settings::get('labdoo_e2e_state');
$options = json_decode(file_get_contents($state . '/v2-readonly-connection.json'), TRUE, 512, JSON_THROW_ON_ERROR);
if ($options['database'] !== 'labdoo_e2e_v2_20261107' || $options['username'] !== 'labdoo_e2e_v2_read') {
  throw new RuntimeException('Use the isolated, SELECT-only V2 fixture database.');
}
Database::removeConnection('external');
Database::addConnectionInfo('external', 'default', $options);
$source = Database::getConnection('default', 'external');
if (!(int) $source->query('SELECT @@session.tx_read_only')->fetchField()) {
  throw new RuntimeException('Source must be read-only.');
}
$check = static function ($ok, $message) {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  echo 'PASS: ' . $message . PHP_EOL;
};
$snapshot = static fn() => Database::getConnection()->query('SELECT (SELECT COUNT(*) FROM node) AS nodes,(SELECT MAX(nid) FROM node) AS nid,(SELECT COUNT(*) FROM node_revision) AS revisions,(SELECT MAX(vid) FROM node_revision) AS vid')->fetchAssoc();
$before = $snapshot();
$storage = \Drupal::entityTypeManager()->getStorage('node');
$write = new ReflectionMethod($storage, 'saveToDedicatedTables');
$write->setAccessible(TRUE);
$labels = \Drupal::keyValue('labdoo_dootrip_location_labels');
$fixtures = [];
foreach ([16086, 16136] as $nid) {
  $node = $storage->load($nid);
  if (!$node || $node->bundle() !== 'dootrip') {
    continue;
  }
  $sourceId = $node->hasField('field_d7_nid') && !$node->get('field_d7_nid')->isEmpty() ? (int) $node->get('field_d7_nid')->value : $nid;
  foreach (['field_origin_of_the_trip', 'field_destination_of_the_trip'] as $field) {
    $row = $source->query('SELECT l.* FROM {location} l JOIN {field_data_' . $field . '} f ON f.' . $field . '_lid=l.lid WHERE f.entity_type=\'node\' AND f.entity_id=:nid AND f.deleted=0', [':nid' => $sourceId])->fetchAssoc();
    if (!$row) {
      throw new RuntimeException('Missing source fixture for ' . $nid);
    }
    // Exercise the small geocoding differences present in the deployed data.
    $node->set($field, ['value' => 'POINT (' . ((float) $row['longitude'] + 0.0005) . ' ' . ((float) $row['latitude'] + 0.0005) . ')']);
    $labels->delete($nid . ':' . $field);
  }
  $write->invoke($storage, $node, TRUE, ['field_origin_of_the_trip', 'field_destination_of_the_trip']);
  $fixtures[] = $nid;
}
$storage->resetCache($fixtures);
$check(count($fixtures) === 2, 'two existing dootrips available');
$command = new DootripLocationTextCommands(new ExternalConnectionManager(), \Drupal::entityTypeManager(), \Drupal::service('keyvalue'), \Drupal::service('country_manager'));
$command->setInput(new ArrayInput([]));
$command->setOutput(new BufferedOutput());
$commandOptions = ['apply' => FALSE, 'nids' => implode(',', $fixtures), 'limit' => 0];
$command->backfill($commandOptions);
$check(!$labels->get('16086:field_origin_of_the_trip'), 'dry run writes no derived labels');
$commandOptions['apply'] = TRUE;
$command->backfill($commandOptions);
$display = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('node.dootrip.default');
foreach (['field_origin_of_the_trip', 'field_destination_of_the_trip'] as $field) {
  $component = $display->getComponent($field);
  $component['type'] = 'labdoo_dootrip_location';
  $display->setComponent($field, $component);
}
$display->save();
foreach ($fixtures as $nid) {
  $node = $storage->load($nid);
  foreach (['field_origin_of_the_trip', 'field_destination_of_the_trip'] as $field) {
    $record = $labels->get($nid . ':' . $field);
    $check(!empty($record['label']), $nid . ': ' . $field . ' has real V2 text');
    $build = $node->get($field)->view($display->getComponent($field));
    $html = (string) \Drupal::service('renderer')->renderRoot($build);
    $check(str_contains(html_entity_decode(strip_tags($html)), $record['label']), $nid . ': recovered text renders');
  }
}
$saved = $labels->getAll();
$command->backfill($commandOptions);
$check($labels->getAll() === $saved, 'second apply is idempotent');
$realGeocoder = \Drupal::service('geocoder');
try {
  foreach (['empty', 'exception'] as $failure) {
    $offline = new class($failure) extends \Drupal\geocoder\Geocoder {
      public function __construct(private string $failure) {}

      public function reverse($latitude, $longitude, array $providers): ?\Geocoder\Model\AddressCollection {
        if ($this->failure === 'exception') {
          throw new RuntimeException('Simulated provider failure');
        }
        return NULL;
      }
    };
    \Drupal::getContainer()->set('geocoder', $offline);
    \Drupal::getContainer()->set('labdoo_common.geocoder_decorator', $offline);
    $node = $storage->load(16086);
    // An edited coordinate must not reuse the address recovered from V2.
    $node->set('field_origin_of_the_trip', ['value' => 'POINT (2.3522 48.8566)']);
    $component = $display->getComponent('field_origin_of_the_trip');
    $plugin = \Drupal::service('plugin.manager.field.formatter')->createInstance('labdoo_dootrip_location', [
      'field_definition' => $node->getFieldDefinition('field_origin_of_the_trip'),
      'settings' => $component['settings'],
      'label' => 'inline',
      'view_mode' => 'default',
      'third_party_settings' => [],
    ]);
    $build = $plugin->viewElements($node->get('field_origin_of_the_trip'), 'en');
    $check(str_contains((string) $build[0]['#plain_text'], '48.856600'), $failure . ': changed coordinates remain visible without provider');
    $check($build[0]['#cache']['max-age'] === 300, $failure . ': fallback is retried after temporary failures');
    $storage->resetCache([16086]);
  }
}
finally {
  \Drupal::getContainer()->set('geocoder', $realGeocoder);
  \Drupal::getContainer()->set('labdoo_common.geocoder_decorator', $realGeocoder);
}
$check($snapshot() === $before, 'reading and recovery allocate no nodes or revisions');
file_put_contents($state . '/dootrip-location-fixtures.json', json_encode($fixtures));
