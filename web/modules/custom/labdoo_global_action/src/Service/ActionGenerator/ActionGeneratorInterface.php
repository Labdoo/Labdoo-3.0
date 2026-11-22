<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\node\NodeInterface;

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
   * @param \Drupal\node\NodeInterface $entity
   *   The source entity.
   * @param \Drupal\node\NodeInterface $globalAction
   *   The global action entity.
   *
   * @return void
   *   The resulting value.
   */
  public function generate(
    NodeInterface $entity,
    NodeInterface $globalAction,
  ): void;

}
