<?php

namespace Drupal\labdoo_filters\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Entity selection limited by references from a source entity.
 */
#[EntityReferenceSelection(
  id: "related_entity_to_source:node",
  label: new TranslatableMarkup("Related entity limited by source entities"),
  entity_types: ["node"],
  group: "default",
  weight: 1
)]
class RelatedEntityToSourceSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);

    // The settings can be in 'handler_settings' or directly in configuration
    $handler_settings = $this->configuration['handler_settings'] ?? $this->configuration;
    $target_type = $handler_settings['target_type'];
    $target_bundles = explode(',', $handler_settings['target_bundles']);
    if ($target_type && $target_bundles) {
      // Get the entity type definition from configuration
      $entity_type = \Drupal::entityTypeManager()->getDefinition($target_type);
      $query->condition($entity_type->getKey('bundle'), $target_bundles, 'IN');
    }

    return $query;
  }

}
