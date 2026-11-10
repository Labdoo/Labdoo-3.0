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
    if (!in_array($component, $allowed, TRUE) || ($value !== NULL && !is_scalar($value))) {
      return NULL;
    }
    $value = trim((string) $value);
    // Empty components must remain mergeable: NULL would replace the whole
    // address accumulated from earlier country/locality mappings.
    if ($component === 'country_code') {
      $value = strtoupper($value);
      if ($value !== '' && !preg_match('/^[A-Z]{2}$/D', $value)) {
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
