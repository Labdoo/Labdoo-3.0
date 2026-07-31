<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\Core\Entity\EntityInterface;

/**
 * Edoovillage action generator.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class EdoovillageActionGenerator extends AbstractActionGenerator implements ActionGeneratorInterface {

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
    $location = $entity->hasField('field_location') ? ($entity->get('field_location')->getValue()[0] ?? []) : [];
    if (empty($location) && $entity->hasField('field_locations')) {
      $location = $entity->get('field_locations')->getValue()[0] ?? [];
    }
    $this->setGeoData($location, $city, $country);

    $title = sprintf(
      'Edoovillage was created in %s, %s',
      $city,
      $country
    );

    $body = $this->buildActionBody((int) $entity->id(), $title, 'edoovillage.png', 30);

    $this->setGlobalAttributes(
      $globalAction,
      'Edoovillage',
      $title,
      $body,
      $entity->id(),
      $entity->hasField('field_hub') ? $entity->get('field_hub')->target_id : NULL,
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
