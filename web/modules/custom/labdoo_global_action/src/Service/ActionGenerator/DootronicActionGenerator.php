<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\node\NodeInterface;

/**
 * Dootronic action generator.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DootronicActionGenerator extends AbstractActionGenerator implements ActionGeneratorInterface {

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
    $location = $entity->hasField('field_locations') ? ($entity->get('field_locations')->getValue()[0] ?? []) : [];
    $this->resolveLocalGeoData($entity, $location, $city, $countryCode);
    $country = $this->getCountryName($countryCode);

    // New Dootronic.
    if ($entity->getOriginal() === NULL) {
      $this->tagGlobalAction(
        $entity,
        $globalAction,
        $city,
        $country,
        $countryCode,
        'was tagged',
        'laptop-simple.png',
        20,
        $location
      );

      return;
    }

    // Discard no-workflow updates.
    $status = $entity->hasField('field_dootronic_status') ? $entity->get('field_dootronic_status')->value : NULL;
    $prevStatus = $entity->getOriginal()->hasField('field_dootronic_status') ? $entity->getOriginal()->get('field_dootronic_status')->value : NULL;
    if ($prevStatus === $status) {
      return;
    }

    // Laptop sanitized.
    if (
      ($prevStatus === 'S0' || $prevStatus === 'S1')
      && ($status === 'S2' || $status === 'S3')
    ) {
      $this->tagGlobalAction(
        $entity,
        $globalAction,
        $city,
        $country,
        $countryCode,
        'was sanitized',
        'laptop-sanitized.png',
        31,
        $location
      );

      return;
    }

    // We take the location from the Edoovillage.
    if ($status === 'S4' || $status === 'T1') {
      $edooVillage = $entity->hasField('field_edoovillage_destination') ? $entity->get('field_edoovillage_destination')->entity : NULL;
      if (!$edooVillage instanceof NodeInterface) {
        return;
      }

      if ($edooVillage->hasField('field_locations')) {
        $location = $edooVillage->get('field_locations')->getValue()[0] ?? [];
      }
      elseif ($edooVillage->hasField('field_location')) {
        $location = $edooVillage->get('field_location')->getValue()[0] ?? [];
      }
      else {
        $location = [];
      }

      $this->resolveLocalGeoData($edooVillage, $location, $city, $countryCode);
      $country = $this->getCountryName($countryCode);
    }

    // Laptop delivered.
    if ($status === 'S4') {
      $this->tagGlobalAction(
        $entity,
        $globalAction,
        $city,
        $country,
        $countryCode,
        'was delivered to an edoovillage',
        'laptop-delivered.png',
        31,
        $location
      );

      return;
    }

    if ($status === 'T1') {
      $this->tagGlobalAction(
        $entity,
        $globalAction,
        $city,
        $country,
        $countryCode,
        'has started its journey to an edoovillage',
        'laptop-dootripped.png',
        34,
        $location
      );

      return;
    }

    if ($status === 'S6') {
      $this->tagGlobalAction(
        $entity,
        $globalAction,
        $city,
        $country,
        $countryCode,
        'has been recycled',
        'laptop-recycled.png',
        27,
        $location
      );
    }
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
    return $entity->hasField('field_locations') && !empty($entity->get('field_locations')->getValue());
  }

  /**
   * Tags a global action.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   * @param \Drupal\node\NodeInterface $globalAction
   *   The global action entity.
   * @param string|null $city
   *   The city.
   * @param string $country
   *   The country.
   * @param string $countryCode
   *   The country code.
   * @param string $action
   *   The action.
   * @param string $picture
   *   The picture.
   * @param int $pictureWidth
   *   The rendered picture width.
   * @param array $location
   *   The geolocation.
   *
   * @return void
   *   The resulting value.
   */
  protected function tagGlobalAction(
    NodeInterface $entity,
    NodeInterface $globalAction,
    ?string $city,
    string $country,
    string $countryCode,
    string $action,
    string $picture,
    int $pictureWidth,
    $location,
  ): void {
    $title = sprintf(
      'Laptop %s %s in %s, %s',
      $entity->label(),
      $action,
      $city ?? '',
      $country
    );

    $body = $this->buildActionBody(
      (int) $entity->id(),
      $title,
      $picture,
      $pictureWidth
    );

    $this->setGlobalAttributes(
      $globalAction,
      'Dooject',
      $title,
      $body,
      $this->referenceNodeId($entity, 'field_edoovillage_destination'),
      $this->referenceNodeId($entity, $entity->hasField('field_hub_laptop') ? 'field_hub_laptop' : 'field_hub'),
      $entity->getOwner()->id(),
      $location,
      $city ?? '',
      $countryCode,
      $entity->get('created')->value,
      $entity->get('changed')->value
    );
  }

}
