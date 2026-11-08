<?php

namespace Drupal\labdoo_dootrip\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\geocoder_geofield\Plugin\Field\FieldFormatter\ReverseGeocodeGeofieldFormatter;

/**
 * Keeps a useful location visible when a reverse geocoding provider fails.
 *
 * @FieldFormatter(
 *   id = "labdoo_dootrip_location",
 *   label = @Translation("Dootrip location text"),
 *   field_types = {"geofield"}
 * )
 */
final class DootripLocationFormatter extends ReverseGeocodeGeofieldFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $node = $items->getEntity();
    $stored = \Drupal::keyValue('labdoo_dootrip_location_labels')->get($node->id() . ':' . $items->getName(), []);
    foreach ($items as $delta => $item) {
      // Recovered V2 text is valid only while the coordinates still match.
      if (!empty($stored['label']) && ($stored['value'] ?? NULL) === $item->value) {
        $elements[$delta] = ['#plain_text' => $stored['label']];
        continue;
      }
      // The parent uses the configured providers and their existing cache.
      // It returns no element at all when Google rejects a server-side key.
      try {
        $resolved = parent::viewElements($items, $langcode);
      }
      catch (\Exception $e) {
        $resolved = [];
      }
      if (!empty($resolved[$delta]['#markup'])) {
        $elements[$delta] = $resolved[$delta];
        continue;
      }
      $geometry = $this->geoPhpWrapper->load($item->value);
      if ($geometry && ($point = $geometry->getCentroid())) {
        $elements[$delta] = [
          '#plain_text' => $this->t('Coordinates: @latitude, @longitude', [
            '@latitude' => number_format($point->y(), 6, '.', ''),
            '@longitude' => number_format($point->x(), 6, '.', ''),
          ]),
          // Retry temporary provider failures; never cache an empty label.
          '#cache' => ['max-age' => 300],
        ];
      }
    }
    $elements['#cache']['tags'][] = 'labdoo_dootrip_location_labels:' . $node->id();
    return $elements;
  }

}
