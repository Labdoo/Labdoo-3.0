<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for action generators.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface ActionGeneratorInterface {

  /**
   * Generates the global action.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The source entity.
   * @param \Drupal\Core\Entity\EntityInterface $globalAction
   *   The global action entity.
   *
   * @return void
   */
  public function generate(
    EntityInterface $entity,
    EntityInterface $globalAction
  ): void;

}
