<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Utility\UpdateException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

/** Attaches existing location storage without resaving content or displays. */
final class LocationFieldConfiguration {

  public const FIELDS = [
    'hub.field_address' => ['uuid' => '80900b2c-8c89-4e2c-9143-b235eb70ec68', 'type' => 'address', 'label' => 'Address'],
    'edoovillage.field_address' => ['uuid' => '624e01dd-198c-4d7d-95ac-a7b4394f6a8f', 'type' => 'address', 'label' => 'Address'],
    'hub.field_city' => ['uuid' => '617fa404-b082-4c74-94d7-a689253b218b', 'type' => 'string', 'label' => 'City'],
  ];

  /**
   * Fail closed on incompatible storage; preserve existing field configuration.
   *
   * Configurable fields are hidden by default. This does not change any form or
   * existing visible form/view component, node, revision, identifier or queue.
   * Drupal records the new fields as hidden display metadata.
   *
   * @return string[]
   *   Newly attached field instance IDs; empty on repeat execution.
   */
  public static function ensure(): array {
    $parent = FieldStorageConfig::loadByName('node', 'field_parent_hub');
    if (!$parent || $parent->getType() !== 'entity_reference' || $parent->getSetting('target_type') !== 'node' || !in_array($parent->getCardinality(), [1, -1], TRUE) || array_diff($parent->getBundles(), ['hub'])) {
      throw new UpdateException('Expected existing hub-only parent reference storage.');
    }
    $storages = [];
    foreach (self::FIELDS as $key => $definition) {
      [$bundle, $field] = explode('.', $key);
      if (!NodeType::load($bundle)) {
        throw new UpdateException('Required location bundle is missing: ' . $bundle);
      }
      $storage = FieldStorageConfig::loadByName('node', $field);
      if (!$storage || $storage->getType() !== $definition['type'] || $storage->getCardinality() !== 1) {
        throw new UpdateException('Expected existing single-value ' . $definition['type'] . ' storage: ' . $field);
      }
      $existing = FieldConfig::loadByName('node', $bundle, $field);
      if ($existing && $existing->getType() !== $definition['type']) {
        throw new UpdateException('Incompatible location field configuration: node.' . $key);
      }
      $storages[$field] = $storage;
    }
    $created = [];
    if ($parent->getCardinality() === 1) {
      // Dedicated field tables already key their current/revision rows by delta.
      // Expanding cardinality preserves every stored reference and revision.
      $parent->setCardinality(-1)->save();
      $created[] = 'node.field_parent_hub:unlimited';
    }
    foreach (self::FIELDS as $key => $definition) {
      [$bundle, $field] = explode('.', $key);
      if (FieldConfig::loadByName('node', $bundle, $field)) {
        continue;
      }
      $settings = $definition['type'] === 'address' ? ['available_countries' => [], 'langcode_override' => '', 'field_overrides' => array_fill_keys(['givenName', 'additionalName', 'familyName', 'organization', 'addressLine1', 'addressLine2', 'addressLine3', 'postalCode', 'sortingCode', 'dependentLocality', 'administrativeArea', 'locality'], ['override' => 'optional']), 'fields' => []] : [];
      FieldConfig::create([
        'uuid' => $definition['uuid'],
        'field_storage' => $storages[$field],
        'bundle' => $bundle,
        'label' => $definition['label'],
        'required' => FALSE,
        'translatable' => FALSE,
        'settings' => $settings,
      ])->save();
      $created[] = 'node.' . $key;
    }
    if ($created) {
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    }
    return $created;
  }
}
