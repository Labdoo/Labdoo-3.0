<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\node\NodeInterface;

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
    NodeInterface $entity,
    NodeInterface $globalAction,
  ): void {
    if (!$this->preConditions($entity)) {
      return;
    }

    $city = '';
    $countryCode = '';
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
    $this->resolveLocalGeoData($entity, $location, $city, $countryCode);

    $titleSplit = explode('- ', $entity->label());
    $titlePart = $titleSplit[1] ?? $titleSplit[0];

    $dootronicId = ($entity->hasField('field_laptops') && !$entity->get('field_laptops')->isEmpty()) ? $entity->get('field_laptops')->target_id : NULL;

    // New Dootrip.
    if ($entity->getOriginal() === NULL) {
      $title = sprintf('Dootrip %s was created', $titlePart);
    }
    else {
      // This Dootrip already exists.
      // Only report new activity if Dootronics have been added to it.
      $prevDootronicId = ($entity->getOriginal()->hasField('field_laptops') && !$entity->getOriginal()->get('field_laptops')->isEmpty()) ? $entity->getOriginal()->get('field_laptops')->target_id : NULL;
      if ($prevDootronicId != NULL or $dootronicId == NULL) {
        return;
      }

      $title = sprintf('Dootrip %s was updated', $titlePart);
    }

    $edooVillageId = NULL;
    $hubId = NULL;
    $dootronic = $dootronicId === NULL ? NULL : $entity->get('field_laptops')->entity;
    if ($dootronic instanceof NodeInterface) {
      $edooVillageId = $this->referenceNodeId($dootronic, 'field_edoovillage_destination');
      $hubId = $this->referenceNodeId($dootronic, $dootronic->hasField('field_hub_laptop') ? 'field_hub_laptop' : 'field_hub');
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
      $countryCode,
      $entity->get('created')->value,
      $entity->get('changed')->value
    );
  }

}
