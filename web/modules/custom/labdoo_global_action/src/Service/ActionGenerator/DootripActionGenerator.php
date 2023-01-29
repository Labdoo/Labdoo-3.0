<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\Core\Entity\EntityInterface;

/**
 * Dootrip action generator.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DootripActionGenerator extends AbstractActionGenerator implements ActionGeneratorInterface {

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
    $location = $entity->get('field_destination_of_the_trip')->getValue();
    if (empty($location)) {
      return;
    }

    $location = reset($location);
    if ($location === NULL) {
      $location = [];
    }
    $this->setGeoData($location, $city, $country);

    $titleSplit = explode('- ', $entity->label());
    $titlePart = $titleSplit[0] ?? $entity->label();

    // New Dootrip.
    if ($entity->original === NULL) {
      $title = sprintf('Dootrip %s was created', $titlePart);
    }
    else {
      // This Dootrip already exists.
      // Only report new activity if Dootronics have been added to it.
      $prevDootronicId = $entity->original->get('field_laptops')->target_id;
      $dootronicId = $entity->get('field_laptops')->target_id;
      if ($prevDootronicId != NULL or $dootronicId == NULL) {
        return;
      }

      $title = sprintf('Dootrip %s was updated', $titlePart);
    }

    $edooVillage = $entity->get('field_edoovillages_assigned')->entity;
    $edooVillageId = $edooVillage ? $edooVillage->id() : NULL;
    $hub = $entity->get('field_hub')->entity;
    $hubId = $hub ? $hub->id() : NULL;

    $body = sprintf(
      '<a href="/node/%d">%s... <img src="/profiles/labdoo/files/pictures/dootrip.png" width="30"></a>',
      $entity->id(),
      $title
    );

    $this->setGlobalAttributes(
      $globalAction,
      'Dootrip',
      $title,
      $body,
      $edooVillageId,
      $hubId,
      $entity->getOwner()->id(),
      $location,
      $city ?? '',
      $entity->get('created')->value,
      $entity->get('changed')->value
    );
  }

}
