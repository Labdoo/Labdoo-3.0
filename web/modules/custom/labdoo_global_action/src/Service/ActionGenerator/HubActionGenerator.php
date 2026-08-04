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
    $countryCode = '';
    $location = $entity->hasField('field_locations') ? ($entity->get('field_locations')->getValue()[0] ?? []) : [];
    $this->resolveLocalGeoData($entity, $location, $city, $countryCode);
    $country = $this->getCountryName($countryCode);

    $title = sprintf(
      'Hub was created in %s, %s',
      $city,
      $country
    );

    $body = $this->buildActionBody((int) $entity->id(), $title, 'hub.png', 30);

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
      $countryCode,
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
    return $entity->original === NULL && $entity->hasField('field_locations') && !empty($entity->get('field_locations')->getValue());
  }

}
