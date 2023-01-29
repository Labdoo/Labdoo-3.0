<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The special field type for paragraphs.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeParagraph implements SpecialFieldTypeInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * SpecialFieldTypeParagraph constructor.
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

    $parameters = [
      'type' => $metadata['bundle'],
      'langcode' => $entity->language()->getId(),
    ];

    if (!is_array($value)) {
      $parameters[$metadata['destination_field']] = $value;
    }
    else {
      foreach ($value as $key => $singleValue) {
        $parameters[$metadata['destination_field'][$key]] = $singleValue;
      }
    }

    $entity = $this->entityTypeManager
      ->getStorage('paragraph')
      ->create($parameters);

    if (
      isset($metadata['formatted_field'])
      && $metadata['formatted_field'] === 'true'
    ) {
      $destinationField = is_array($metadata['destination_field']) ?
        reset($metadata['destination_field']) : $metadata['destination_field'];
      $entity->{$destinationField}->format = 'full_html';
    }

    $entity->save();

    return [
      'target_id' => $entity->id(),
      'target_revision_id' => $entity->getRevisionId(),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
