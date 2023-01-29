<?php

namespace Drupal\labdoo_notifications\Service\EntityProcessor;

use Drupal\labdoo_notifications\Exception\UnknownEntityProcessorException;

/**
 * Factory for EntityProcessor services.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class EntityProcessorFactory {

  /**
   * Retrieves a service compatible with the entity type provided.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $bundle
   *   The entity bundle.
   *
   * @return \Drupal\labdoo_notifications\Service\EntityProcessor\EntityProcessorInterface
   *   The EntityProcessor service.
   *
   * @throws \Drupal\labdoo_notifications\Exception\UnknownEntityProcessorException
   *
   * @codeCoverageIgnore
   */
  public static function get(string $entityType, string $bundle): EntityProcessorInterface {
    $serviceId = sprintf('labdoo_notifications.entity_processor.%s', $entityType);
    try {
      $service = \Drupal::service($serviceId);
      if ($service instanceof EntityProcessorInterface) {
        return $service;
      }
    }
    catch (\Exception $e) {
    }

    // Fallback to the bundle (for content types).
    $serviceId = sprintf('labdoo_notifications.entity_processor.%s', $bundle);
    try {
      $service = \Drupal::service($serviceId);
      if ($service instanceof EntityProcessorInterface) {
        return $service;
      }
    }
    catch (\Exception $e) {
    }

    throw new UnknownEntityProcessorException(sprintf('Unknown entity type or bundle: %s (%s)', $entityType, $bundle));
  }

}
