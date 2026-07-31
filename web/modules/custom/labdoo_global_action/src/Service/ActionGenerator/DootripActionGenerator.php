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
    $location = $entity->hasField('field_origin_of_the_trip') ? $entity->get('field_origin_of_the_trip')->getValue() : [];
    if (empty($location)) {
      $location = $entity->hasField('field_destination_of_the_trip') ? $entity->get('field_destination_of_the_trip')->getValue() : [];
    }
    if (empty($location)) {
      return;
    }

    $location = reset($location);
    if ($location === NULL) {
      $location = [];
    }
    $this->setGeoData($location, $city, $country);

    $titleSplit = explode('- ', $entity->label());
    $titlePart = $titleSplit[1] ?? ($titleSplit[0] ?? $entity->label());

    $dootronicId = ($entity->hasField('field_laptops') && !$entity->get('field_laptops')->isEmpty()) ? $entity->get('field_laptops')->target_id : NULL;

    // New Dootrip.
    if ($entity->original === NULL) {
      $title = sprintf('Dootrip %s was created', $titlePart);
    }
    else {
      // This Dootrip already exists.
      // Only report new activity if Dootronics have been added to it.
      $prevDootronicId = ($entity->original->hasField('field_laptops') && !$entity->original->get('field_laptops')->isEmpty()) ? $entity->original->get('field_laptops')->target_id : NULL;
      if ($prevDootronicId != NULL or $dootronicId == NULL) {
        return;
      }

      $title = sprintf('Dootrip %s was updated', $titlePart);
    }

    $edooVillageId = NULL;
    $hubId = NULL;
    if ($dootronicId !== NULL && $entity->hasField('field_laptops') && $entity->get('field_laptops')->entity !== NULL) {
      $dootronic = $entity->get('field_laptops')->entity;
      $edooVillageId = $dootronic->hasField('field_edoovillage_destination') ? $dootronic->get('field_edoovillage_destination')->target_id : NULL;
      $hubId = $dootronic->hasField('field_hub_laptop') ? $dootronic->get('field_hub_laptop')->target_id : ($dootronic->hasField('field_hub') ? $dootronic->get('field_hub')->target_id : NULL);
    }

    $body = $this->buildActionBody((int) $entity->id(), $title, 'dootrip.png', 30);

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
