<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\labdoo_global_action\Exception\UnknownServiceException;

/**
 * ActionGenerator factory.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ActionGeneratorFactory {

  /**
   * Retrieves a service compatible with the entity type provided.
   *
   * @param string $bundle
   *   The entity bundle.
   *
   * @return \Drupal\labdoo_global_action\Service\ActionGenerator\ActionGeneratorInterface
   *   The ActionGenerator service.
   *
   * @throws \Drupal\labdoo_global_action\Exception\UnknownServiceException
   * @codeCoverageIgnore
   */
  public static function get(string $bundle): ActionGeneratorInterface {
    $serviceId = sprintf('labdoo_global_action.action_generator.%s', $bundle);
    try {
      $service = \Drupal::service($serviceId);
      if ($service instanceof ActionGeneratorInterface) {
        return $service;
      }
    }
    catch (\Exception $e) {
    }

    throw new UnknownServiceException('Unknown entity type');
  }

}
