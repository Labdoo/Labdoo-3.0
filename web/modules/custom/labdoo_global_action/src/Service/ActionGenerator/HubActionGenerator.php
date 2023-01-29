<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\Core\Entity\EntityInterface;

/**
 * Hub action generator.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class HubActionGenerator extends AbstractActionGenerator implements ActionGeneratorInterface {

  /**
   * {@inheritDoc}
   */
  public function generate(
    EntityInterface $entity,
    EntityInterface $globalAction
  ): void {
    if (!$this->preConditions($entity)) {
      return;
    }

    $city = '';
    $country = '';
    $location = $entity->get('field_locations')->getValue()[0] ?? [];
    $this->setGeoData($location, $city, $country);

    $title = sprintf(
      'Hub was created in %s, %s',
      $city,
      $country
    );

    $body = sprintf(
      '<a href="/node/%s">%s... <img src="/themes/custom/labdoo/img/hub.png" width="30"></a>',
      $entity->id(),
      $title
    );

    $this->setGlobalAttributes(
      $globalAction,
      'Hub',
      $title,
      $body,
      NULL,
      $entity->id(),
      $entity->getOwner()->id(),
      $location,
      $city ?? '',
      $entity->get('created')->value,
      $entity->get('changed')->value
    );
  }

  /**
   * Checks the preconditions of this action.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE if the preconditions match, otherwise FALSE.
   */
  protected function preConditions(EntityInterface $entity): bool {
    return $entity->original === NULL;
  }

}
