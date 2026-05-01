<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The abstract class for special field type of related entities.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class AbstractSpecialFieldTypeRelatedEntity implements SpecialFieldTypeInterface {

  /**
   * The entity key ID.
   *
   * @var string
   */
  protected string $entityIdKey;

  /**
   * The entity value key.
   *
   * @var string
   */
  protected string $entityValueKey;

  /**
   * The language code key.
   *
   * @var string
   */
  protected string $langCodeKey;

  /**
   * The bundle.
   *
   * @var string
   */
  protected string $bundle;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * AbstractSpecialFieldTypeRelatedEntity constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {

    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(
    $value,
    array $metadata,
    EntityInterface $entity,
    string $mainLangCode
  ) {

    if (!$value) {
      return NULL;
    }

    $isSourceMultiple = $this->isSourceMultiple($metadata);
    $isDestinationMultiple = $this->isDestinationMultiple($metadata);

    $langCode = $entity->language() ? $entity->language()->getId() : $mainLangCode;

    if ($isSourceMultiple) {
      $result = $this->processSourceMultiple(
        $value,
        $metadata,
        $langCode,
        $mainLangCode
      );
    }
    else {
      $result = $this->processSourceSingle(
        $value,
        $metadata,
        $langCode,
        $mainLangCode
      );
      $result = $isDestinationMultiple ? [$result] : $result;
    }

    return $result;
  }

  /**
   * Processes a multiple source.
   *
   * @param mixed $value
   *   The value.
   * @param array $metadata
   *   The metadata array.
   * @param string $langCode
   *   The language code.
   *
   * @return array
   *   Returns an array with the processed entities.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function processSourceMultiple(
    $value,
    array $metadata,
    string $langCode,
    string $mainLangCode
  ): array {

    $sourceSeparator = $this->getSourceSeparator($metadata);
    $values = explode($sourceSeparator, $value);

    $entities = [];
    foreach ($values as $singleValue) {
      $entities[] = $this->processSourceSingle(
        trim($singleValue),
        $metadata,
        $langCode,
        $mainLangCode
      );
    }

    return $entities;
  }

  /**
   * Processes a single source.
   *
   * @param mixed $value
   *   The value.
   * @param array $metadata
   *   The metadata array.
   * @param string $langCode
   *   The language code.
   * @param string $mainLangCode
   *   The main language code.
   *
   * @return int|string
   *   Returns the processed entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function processSourceSingle(
    $value,
    array $metadata,
    string $langCode,
    string $mainLangCode
  ) {

    if (($entity = $this->entityExists($value, $metadata, $langCode, $mainLangCode))) {
      return $entity;
    }

    return $this->saveEntity($value, $metadata, $langCode);
  }

  /**
   * Checks if the source is multiple.
   *
   * @param array $metadata
   *   The metadata array.
   *
   * @return bool
   *   Returns TRUE if the source is multiple, otherwise FALSE.
   */
  protected function isSourceMultiple(array $metadata): bool {

    return isset($metadata['source_is_multiple'])
      && strtolower($metadata['source_is_multiple']) === 'true';
  }

  /**
   * Retrieves the source separator.
   *
   * @param array $metadata
   *   The metadata array.
   *
   * @return string
   *   Returns the source separator.
   */
  protected function getSourceSeparator(array $metadata): string {

    return $metadata['source_separator'] ?? '';
  }

  /**
   * Checks if the destination is multiple.
   *
   * @param array $metadata
   *   The metadata array.
   *
   * @return bool
   *   Returns TRUE if the destination is multiple, otherwise FALSE.
   */
  protected function isDestinationMultiple(array $metadata): bool {

    return isset($metadata['destination_is_multiple'])
      && strtolower($metadata['destination_is_multiple']) === 'true';
  }

  /**
   * Retrieves the entity if it exists.
   *
   * @param mixed $value
   *   The value.
   * @param array $metadata
   *   The metadata array.
   * @param string|null $langCode
   *   The language code.
   * @param string|null $mainLangCode
   *   The main language code.
   *
   * @return false|int|string
   *   Returns the entity if it exits, otherwise FALSE.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function entityExists(
    $value,
    array $metadata,
    ?string $langCode,
    ?string $mainLangCode
  ) {

    $properties = [
      $this->entityIdKey => $metadata['type_id'],
      $this->entityValueKey => $value,
    ];
    if ($langCode !== NULL) {
      $properties[$this->langCodeKey] = $langCode;
    }
    $results = $this->entityTypeManager
      ->getStorage($this->bundle)
      ->loadByProperties($properties);

    return $results ? array_keys($results)[0] : FALSE;
  }

  /**
   * Saves an entity.
   *
   * @param mixed $value
   *   The value.
   * @param array $metadata
   *   The metadata array.
   * @param string $langCode
   *   The language code.
   *
   * @return int|string|null
   *   Returns the entity ID or NULL in case no value is provided.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function saveEntity($value, array $metadata, string $langCode) {

    if (!$value) {
      return NULL;
    }

    $entity = $this->entityTypeManager
      ->getStorage($this->bundle)
      ->create([
        $this->entityIdKey => $metadata['type_id'],
        $this->entityValueKey => $value,
        $this->langCodeKey => $langCode,
      ]);
    $entity->save();

    return $entity->id();
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
