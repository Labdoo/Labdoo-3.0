<?php

declare(strict_types=1);

use Drupal\field\Entity\FieldStorageConfig;

$storage = FieldStorageConfig::loadByName('node', 'field_dootrip_weight');
if (!$storage) {
  throw new RuntimeException('Dootrip weight field storage was not found.');
}

$database = \Drupal::database();
$database_name = $database->getConnectionOptions()['database'];
$column_limit = $database->query(
  'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table AND COLUMN_NAME = :column',
  [
    ':database' => $database_name,
    ':table' => 'node__field_dootrip_weight',
    ':column' => 'field_dootrip_weight_value',
  ],
)->fetchField();
$values = $database->query(
  'SELECT COUNT(*) AS total, MAX(CHAR_LENGTH(field_dootrip_weight_value)) AS max_length, SUM(CHAR_LENGTH(field_dootrip_weight_value) > 100) AS over_100 FROM node__field_dootrip_weight WHERE deleted = 0',
)->fetchAssoc();
$configured_limit = (int) $storage->getSetting('max_length');
$column_limit = (int) $column_limit;
$over_100 = (int) $values['over_100'];
$maximum_existing_value = (int) $values['max_length'];

if ($configured_limit !== 255 || $column_limit !== 255 || $over_100 < 1 || $maximum_existing_value > $configured_limit) {
  throw new RuntimeException(sprintf(
    'Dootrip weight storage is inconsistent: configured=%d, column=%d, values over 100=%d, maximum existing value=%d.',
    $configured_limit,
    $column_limit,
    $over_100,
    $maximum_existing_value,
  ));
}

printf("weight_storage_limit=%d\nweight_column_limit=%d\nvalues_over_100=%d\nmaximum_existing_value=%d\n", $configured_limit, $column_limit, $over_100, $maximum_existing_value);
