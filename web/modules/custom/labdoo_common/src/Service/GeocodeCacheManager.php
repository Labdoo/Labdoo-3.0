<?php

namespace Drupal\labdoo_common\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Provides reusable geocode cache operations for any fieldable entity.
 */
class GeocodeCacheManager {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Constructs the geocode cache manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * Applies cached coordinates to empty geofields configured with geocoder.
   */
  public function applyCacheToEntity(FieldableEntityInterface $entity): int {
    $applied = 0;
    foreach ($this->getGeocoderMappings($entity) as $geofield_name => $source_field_name) {
      if (!$entity->get($geofield_name)->isEmpty()) {
        continue;
      }

      $address = $this->getSourceAddressValue($entity, $source_field_name);
      if (!$address) {
        continue;
      }

      $coordinates = $this->getCachedCoordinates($address);
      if ($coordinates === NULL) {
        continue;
      }

      $this->applyCoordinatesToGeofield($entity, $geofield_name, $coordinates);
      $applied++;
    }

    return $applied;
  }

  /**
   * Persists geocoded values from entity geofields into the cache.
   */
  public function storeEntityCoordinates(FieldableEntityInterface $entity): int {
    $stored = 0;
    foreach ($this->getGeocoderMappings($entity) as $geofield_name => $source_field_name) {
      $address = $this->getSourceAddressValue($entity, $source_field_name);
      $coordinates = $this->extractCoordinatesFromGeofield($entity, $geofield_name);

      if (!$address || $coordinates === NULL) {
        continue;
      }

      $this->storeCachedCoordinates($address, $coordinates);
      $stored++;
    }

    return $stored;
  }

  /**
   * Returns cached coordinates for an address.
   */
  public function getCachedCoordinates(string $address): ?array {
    $normalized = $this->normalizeAddressKey($address);
    if ($normalized === '') {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('labdoo_geocode_cache');
    $matches = $storage->loadByProperties(['address_normalized' => $normalized]);
    $entry = reset($matches);
    if (!$entry) {
      return NULL;
    }

    $hits = (int) $entry->get('hits')->value;
    $entry->set('hits', $hits + 1);
    $entry->save();

    return [
      'lat' => (float) $entry->get('latitude')->value,
      'lon' => (float) $entry->get('longitude')->value,
    ];
  }

  /**
   * Stores or updates coordinates for an address.
   */
  public function storeCachedCoordinates(string $address, array $coordinates): void {
    if (!isset($coordinates['lat'], $coordinates['lon'])) {
      return;
    }

    $normalized = $this->normalizeAddressKey($address);
    if ($normalized === '') {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('labdoo_geocode_cache');
    $matches = $storage->loadByProperties(['address_normalized' => $normalized]);
    $entry = reset($matches);

    if (!$entry) {
      $entry = $storage->create([
        'address_normalized' => $normalized,
        'hits' => 0,
      ]);
    }

    $entry->set('address_original', $address);
    $entry->set('latitude', (float) $coordinates['lat']);
    $entry->set('longitude', (float) $coordinates['lon']);
    $entry->save();
  }

  /**
   * Resolves geofield => source field mappings from geocoder settings.
   */
  public function getGeocoderMappings(FieldableEntityInterface $entity): array {
    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    $mappings = [];
    foreach ($fields as $field_name => $field_definition) {
      if ($field_definition->getType() !== 'geofield') {
        continue;
      }

      $geocoder_settings = $field_definition->getThirdPartySettings('geocoder_field');
      $source_field_name = $geocoder_settings['field'] ?? NULL;
      if (!$source_field_name || !isset($fields[$source_field_name])) {
        continue;
      }

      $mappings[$field_name] = $source_field_name;
    }

    return $mappings;
  }

  /**
   * Gets a geocodable address string from the configured source field.
   */
  public function getSourceAddressValue(FieldableEntityInterface $entity, string $sourceFieldName): ?string {
    if (!$entity->hasField($sourceFieldName) || $entity->get($sourceFieldName)->isEmpty()) {
      return NULL;
    }

    $values = $entity->get($sourceFieldName)->getValue();
    if (empty($values[0]) || !is_array($values[0])) {
      return NULL;
    }

    $item = $values[0];
    foreach (['value', 'address', 'address_line1'] as $key) {
      if (!empty($item[$key]) && is_string($item[$key])) {
        $value = trim($item[$key]);
        return $value !== '' ? $value : NULL;
      }
    }

    $scalar_values = array_filter($item, static fn($value) => is_scalar($value) && trim((string) $value) !== '');
    if (empty($scalar_values)) {
      return NULL;
    }

    return trim(implode(' ', $scalar_values)) ?: NULL;
  }

  /**
   * Normalizes an address key for cache lookup.
   */
  public function normalizeAddressKey(string $address): string {
    $normalized = mb_strtolower(trim($address));
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return mb_substr($normalized, 0, 255);
  }

  /**
   * Applies coordinates to a geofield as WKT point.
   */
  public function applyCoordinatesToGeofield(FieldableEntityInterface $entity, string $geofieldName, array $coordinates): void {
    $entity->set($geofieldName, ['value' => sprintf('POINT (%F %F)', (float) $coordinates['lon'], (float) $coordinates['lat'])]);
  }

  /**
   * Extracts coordinates from a geofield WKT value.
   */
  public function extractCoordinatesFromGeofield(FieldableEntityInterface $entity, string $geofieldName): ?array {
    if (!$entity->hasField($geofieldName) || $entity->get($geofieldName)->isEmpty()) {
      return NULL;
    }

    $first_item = $entity->get($geofieldName)->first();
    if (!$first_item) {
      return NULL;
    }

    $item_value = $first_item->getValue();
    $value = $item_value['value'] ?? NULL;
    if (!is_string($value)) {
      return NULL;
    }

    if (!preg_match('/POINT\s*\(\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*\)/i', $value, $matches)) {
      return NULL;
    }

    return [
      'lon' => (float) $matches[1],
      'lat' => (float) $matches[2],
    ];
  }

}
