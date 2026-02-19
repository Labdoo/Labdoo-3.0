<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;
use Drupal\labdoo_migrate\Traits\TextFormatMapperTrait;

/**
 * The special field type for formatted text fields.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeFormattedText implements SpecialFieldTypeInterface {

  use TextFormatMapperTrait;

  /**
   * {@inheritDoc}
   */
  public function getValue(
    $value,
    array $metadata,
    EntityInterface $entity,
    string $mainLangCode
  ) {

    return [
      'value' => $value,
      'format' => $this->mapFormat($metadata['format']),
    ];
  }


  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
