<?php

namespace Drupal\labdoo_global_action\Service\ActionGenerator;

use Drupal\Core\Entity\EntityInterface;

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
    EntityInterface $entity,
    EntityInterface $globalAction
  ): void {
    if (!$this->preConditions($entity)) {
      return;
    }

    $city = '';
    $country = '';
    $location = $entity->get('field_location')->getValue()[0] ?? [];
    $this->setGeoData($location, $city, $country);

    // New Dootronic.
    if ($entity->isNew()) {
      $this->tagGlobalAction(
        $entity,
        $globalAction,
        $city,
        $country,
        'was tagged',
        'laptop-simple.png',
        $location
      );

      return;
    }

    // Discard no-workflow updates.
    $status = $entity->get('field_dootronic_status')->value;
    if ($entity->original !== NULL) {
      $prevStatus = $entity->original->get('field_dootronic_status')->value;
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
          'was sanitized',
          'laptop-sanitized.png',
          $location ?? ''
        );

        return;
      }
    }

    // We take the location from the Edoovillage.
    if ($status === 'S4' || $status === 'T1') {
      $edooVillage = $entity->get('field_edoovillage_destination')->entity;
      if ($edooVillage === NULL) {
        return;
      }

      $location = $edooVillage->get('field_location')->getValue()[0] ?? [];
      $this->setGeoData($location, $city, $country);
    }

    // Laptop delivered.
    if ($status === 'S4') {
      $this->tagGlobalAction(
        $entity,
        $globalAction,
        $city,
        $country,
        'was delivered to an edoovillage',
        'laptop-delivered.png',
        $location ?? ''
      );

      return;
    }

    if ($status === 'T1') {
      $this->tagGlobalAction(
        $entity,
        $globalAction,
        $city,
        $country,
        'has started its journey to an edoovillage',
        'laptop-dootripped.png',
        $location ?? ''
      );

      return;
    }

    if ($status === 'S6') {
      $this->tagGlobalAction(
        $entity,
        $globalAction,
        $city,
        $country,
        'has been recycled',
        'laptop-recycled.png',
        $location ?? ''
      );
    }
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
    return !empty($entity->get('field_location')->getValue());
  }

  /**
   * Tags a global action.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Entity\EntityInterface $globalAction
   *   The global action entity.
   * @param string|null $city
   *   The city.
   * @param string $country
   *   The country.
   * @param string $action
   *   The action.
   * @param string $picture
   *   The picture.
   * @param $location
   *   The geolocation.
   *
   * @return void
   */
  protected function tagGlobalAction(
    EntityInterface $entity,
    EntityInterface $globalAction,
    ?string $city,
    string $country,
    string $action,
    string $picture,
    $location
  ): void {
    $title = sprintf(
      'Laptop %s %s in %s, %s',
      $entity->label(),
      $action,
      $city ?? '',
      $country
    );

    $body = sprintf(
      '<a href="/node/%s">%s... <img src="/themes/custom/labdoo/img/%s" width="31"></a>',
      $entity->id(),
      $title,
      $picture
    );

    $this->setGlobalAttributes(
      $globalAction,
      'Dooject',
      $title,
      $body,
      $entity->get('field_edoovillage_destination')->target_id,
      $entity->get('field_hub')->target_id,
      $entity->getOwner()->id(),
      $location,
      $city ?? '',
      $entity->get('created')->value,
      $entity->get('changed')->value
    );
  }

}
