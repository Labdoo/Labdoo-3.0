<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The special field type for media.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeMedia implements SpecialFieldTypeInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * SpecialFieldTypeMedia constructor.
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

    if (!isset($metadata['fields'])) {
      return NULL;
    }

    $fieldValues = [];
    foreach ($metadata['fields'] as $fieldDefinition) {
      $destinationKey = $fieldDefinition['destination'];
      $sourceKey = $fieldDefinition['source'];
      if ($value[$sourceKey]) {
        $fieldValues[$destinationKey] = $value[$sourceKey];
      }
    }

    if (!$fieldValues) {
      return NULL;
    }

    $fieldValues = array_merge(
      $fieldValues,
      [
        'bundle' => $metadata['bundle'],
        'status' => TRUE,
        'langcode' => $entity->language()->getId(),
      ]
    );

    $entity = $this->entityTypeManager
      ->getStorage('media')
      ->create($fieldValues);
    $entity->save();

    return ['target_id' => $entity->id()];
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
