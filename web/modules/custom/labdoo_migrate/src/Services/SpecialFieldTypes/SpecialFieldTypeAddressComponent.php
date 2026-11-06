<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;

/**
 * Maps a legacy location value to a structured Drupal address component.
 */
class SpecialFieldTypeAddressComponent implements SpecialFieldTypeInterface {

  /**
   * {@inheritdoc}
   */
  public function getValue($value, array $metadata, EntityInterface $entity, string $mainLangCode) {
    $component = $metadata['component'] ?? '';
    $allowed = [
      'country_code', 'locality', 'postal_code', 'address_line1',
      'administrative_area',
    ];
    if (!in_array($component, $allowed, TRUE) || !is_scalar($value)) {
      return NULL;
    }
    $value = trim((string) $value);
    if ($value === '') {
      return NULL;
    }
    if ($component === 'country_code') {
      $value = strtoupper($value);
      if (!preg_match('/^[A-Z]{2}$/D', $value)) {
        return NULL;
      }
    }
    return [$component => $value];
  }

  /**
   * {@inheritdoc}
   */
  public function filterValue($value) {
    return $value;
  }

}
