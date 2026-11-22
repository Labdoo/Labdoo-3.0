<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\node\NodeInterface;

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
    NodeInterface $entity,
    NodeInterface $globalAction,
  ): void {
    if (!$this->preConditions($entity)) {
      return;
    }

    $city = '';
    $countryCode = '';
    $location = $entity->hasField('field_location') ? ($entity->get('field_location')->getValue()[0] ?? []) : [];
    if (empty($location) && $entity->hasField('field_locations')) {
      $location = $entity->get('field_locations')->getValue()[0] ?? [];
    }
    $this->resolveLocalGeoData($entity, $location, $city, $countryCode);
    $country = $this->getCountryName($countryCode);

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
      $this->referenceNodeId($entity, 'field_hub'),
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
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE if the preconditions match, otherwise FALSE.
   */
  protected function preConditions(NodeInterface $entity): bool {
    return $entity->getOriginal() === NULL;
  }

}
