<?php

namespace Drupal\labdoo_common\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\labdoo_common\Service\GeocodeCacheManager;
use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * A Drush commandfile.
 */
class GeocodeCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The geocode cache manager.
   */
  protected GeocodeCacheManager $geocodeCacheManager;

  /**
   * GeocodeCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\labdoo_common\Service\GeocodeCacheManager $geocodeCacheManager
   *   The geocode cache manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, GeocodeCacheManager $geocodeCacheManager) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->geocodeCacheManager = $geocodeCacheManager;
  }

  /**
   * Regeocodes entities of a bundle by detecting its geofield.
   *
   * @param string $bundle
   *   The entity bundle (e.g. edoovillage, hub, etc.).
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   *
   * @option entity_type The entity type (defaults to 'node').
   * @option limit Limits the number of entities to process.
   * @usage drush labdoo:regeocode edoovillage
   *   Regeocodes all nodes of type edoovillage.
   * @usage drush labdoo:regeocode hub --entity_type=node
   *   Regeocodes all nodes of type hub.
   *
   * @command labdoo:regeocode
   * @aliases lregeo
   */
  public function regeocode(
    string $bundle,
    array $options = ['entity_type' => 'node', 'limit' => NULL]
  ): void {
    $this->processRegeocode($bundle, $options, FALSE);
  }

  /**
   * Regeocodes only entities in a bundle that do not have coordinates.
   *
   * @param string $bundle
   *   The entity bundle (e.g. edoovillage, hub, etc.).
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   *
   * @option entity_type The entity type (defaults to 'node').
   * @option limit Limits the number of entities to evaluate.
   * @usage drush labdoo:regeocode-missing edoovillage
   *   Regeocodes nodes of type edoovillage without coordinates.
   * @usage drush labdoo:regeocode-missing hub --entity_type=node --limit=100
   *   Regeocodes up to 100 nodes of type hub without coordinates.
   *
   * @command labdoo:regeocode-missing
   * @aliases lregeom
   */
  public function regeocodeMissing(
    string $bundle,
    array $options = ['entity_type' => 'node', 'limit' => NULL]
  ): void {
    $this->processRegeocode($bundle, $options, TRUE);
  }

  /**
   * Shared regeocoding logic.
   *
   * @param string $bundle
   *   The entity bundle.
   * @param array $options
   *   Command options.
   * @param bool $onlyMissing
   *   When TRUE, only regeocodes entities with an empty geofield.
   */
  protected function processRegeocode(string $bundle, array $options, bool $onlyMissing): void {
    $entity_type = $options['entity_type'];

    // Find geofield fields for this bundle.
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    $geofield_name = NULL;

    foreach ($fields as $field_name => $field_definition) {
      if ($field_definition->getType() === 'geofield') {
        $geofield_name = $field_name;
        break;
      }
    }

    if (!$geofield_name) {
      $this->logger()->error(dt('No geofield field was found for bundle "@bundle" in entity type "@entity_type".', [
        '@bundle' => $bundle,
        '@entity_type' => $entity_type,
      ]));
      return;
    }

    $this->logger()->notice(dt('Using field "@field" for regeocoding.', ['@field' => $geofield_name]));

    $storage = $this->entityTypeManager->getStorage($entity_type);
    $key = $this->entityTypeManager
      ->getDefinition($entity_type)
      ->getKey('bundle');
    $query = $storage->getQuery()
      ->condition($key, $bundle)
      ->accessCheck(FALSE);

    if (!empty($options['limit'])) {
      $query->range(0, (int) $options['limit']);
    }

    $ids = $query->execute();

    if (empty($ids)) {
      $this->logger()->warning(dt('No entities were found to regeocode.'));
      return;
    }

    $total = count($ids);
    $mode_message = $onlyMissing
      ? dt('Evaluating @count entities to regeocode only those without coordinates...', ['@count' => $total])
      : dt('Regeocoding @count entities...', ['@count' => $total]);
    $this->output()->writeln($mode_message);

    $chunks = array_chunk($ids, 50);
    $processed = 0;
    $regeocoded = 0;
    $cache_hits = 0;
    $cache_stores = 0;
    $source_field_name = $this->getSourceFieldName($fields, $geofield_name);

    foreach ($chunks as $chunk) {
      $entities = $storage->loadMultiple($chunk);
      foreach ($entities as $entity) {
        if (!($entity instanceof FieldableEntityInterface)) {
          continue;
        }

        $processed++;

        if ($onlyMissing && (!$entity->hasField($geofield_name) || !$entity->get($geofield_name)->isEmpty())) {
          continue;
        }

        try {
          $address_value = $source_field_name ? $this->getSourceAddressValue($entity, $source_field_name) : NULL;
          if ($address_value) {
            $cached_coordinates = $this->geocodeCacheManager->getCachedCoordinates($address_value);
            if ($cached_coordinates !== NULL) {
              $this->geocodeCacheManager->applyCoordinatesToGeofield($entity, $geofield_name, $cached_coordinates);
              $cache_hits++;
            }
          }

          // Ensure the entity is not treated as new if it already has an ID.
          if (!$entity->isNew()) {
            $entity->enforceIsNew(FALSE);
          }
          $entity->save();
          $regeocoded++;

          $coordinates = $this->geocodeCacheManager->extractCoordinatesFromGeofield($entity, $geofield_name);
          if (!empty($address_value) && $coordinates !== NULL) {
            $this->geocodeCacheManager->storeCachedCoordinates($address_value, $coordinates);
            $cache_stores++;
          }
        }
        catch (\Exception $e) {
          $this->logger()->error(dt('Error saving entity @id: @message', [
            '@id' => $entity->id(),
            '@message' => $e->getMessage(),
          ]));
        }
      }

      // Clear the storage static cache to free memory.
      $storage->resetCache($chunk);

      $this->output()->writeln(dt('Evaluated @count of @total. Regeocoded: @regeocoded.', [
        '@count' => $processed,
        '@total' => $total,
        '@regeocoded' => $regeocoded,
      ]));
    }

    $this->logger()->notice(dt('Geocode cache hits: @hits. Cache entries stored/updated: @stores.', [
      '@hits' => $cache_hits,
      '@stores' => $cache_stores,
    ]));

    if ($onlyMissing) {
      $this->logger()->success(dt('Evaluated @processed entities and regeocoded @regeocoded without coordinates.', [
        '@processed' => $processed,
        '@regeocoded' => $regeocoded,
      ]));
      return;
    }

    $this->logger()->success(dt('Processed @count entities.', ['@count' => $regeocoded]));
  }

  /**
   * Resolves the source field configured for geocoding.
   */
  protected function getSourceFieldName(array $fields, string $geofieldName): ?string {
    if (!isset($fields[$geofieldName])) {
      return NULL;
    }

    $geocoder_settings = $fields[$geofieldName]->getThirdPartySettings('geocoder_field');
    if (empty($geocoder_settings['field']) || !isset($fields[$geocoder_settings['field']])) {
      return NULL;
    }

    return $geocoder_settings['field'];
  }

  /**
   * Gets a geocodable address string from the configured source field.
   */
  protected function getSourceAddressValue(FieldableEntityInterface $entity, string $sourceFieldName): ?string {
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

}
