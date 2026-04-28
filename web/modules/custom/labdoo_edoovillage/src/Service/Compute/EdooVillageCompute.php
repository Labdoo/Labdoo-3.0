<?php

namespace Drupal\labdoo_edoovillage\Service\Compute;

use Drupal\Core\Entity\EntityInterface;

/**
 * Service to compute EdooVillage data.
 */
class EdooVillageCompute implements EdooVillageComputeInterface {

  /**
   * {@inheritDoc}
   */
  public function setEdooVillageTitle(EntityInterface $entity): void {
    if ($entity->bundle() !== 'edoovillage') {
      return;
    }

    $title = $entity->getTitle();

    // 1. Obtain unique ID and Prefix.
    // Follow the logic from v2: labdoo_lib_node_presave.
    $edoovillagePrefix = "";
    $edoovillageWords = explode(' ', $title);

    if (!$entity->isNew()) {
      // This is an update of an existing edoovillage.
      if (!isset($edoovillageWords[0]) || $edoovillageWords[0] !== "Edoovillage") {
        // Support legacy naming convention for older edoovillages (name without IDs).
        $edoovillagePrefix = "";
      }
      else {
        // Preserve the existing ID.
        if (isset($edoovillageWords[1])) {
          $edoovillageId = explode('#', $edoovillageWords[1]);
          if (isset($edoovillageId[1])) {
            $edoovillagePrefix = "Edoovillage #" . $edoovillageId[1] . " - ";
          }
        }

        // If for some reason we couldn't extract the ID from "Edoovillage ...",
        // we might need to allocate one (though v2 assumes it's there).
        if (empty($edoovillagePrefix)) {
          $edoovillagePrefix = "Edoovillage #" . $this->allocateNewId() . " - ";
        }
      }
    }
    else {
      // This is the creation of a new edoovillage.
      // Generate a new ID atomically.
      $edoovillagePrefix = "Edoovillage #" . $this->allocateNewId() . " - ";
    }

    // 2. Location Construction: [Country], [City]
    $countryCode = !$entity->get('field_country')->isEmpty() ? $entity->get('field_country')->value : '';
    $nodeCity = '';
    if ($entity->hasField('field_city') && !$entity->get('field_city')->isEmpty()) {
      $nodeCity = $entity->get('field_city')->value;
    }

    $nodeCountry = $this->countryCodeToName($countryCode);

    if ($nodeCountry === "[country not defined]") {
      if (empty($nodeCity)) {
        // If neither country nor city is defined, we check if geocoding is likely pending.
        if ($entity->hasField('field_location') && !$entity->get('field_location')->isEmpty()) {
          $edoovillagePrefix .= "[geocoding...]";
        }
      }
      else {
        $edoovillagePrefix .= $nodeCity;
      }
    }
    else {
      $edoovillagePrefix .= $nodeCountry . ($nodeCity ? ", " . $nodeCity : "");
    }

    // 3. Inclusion of Project Summary.
    // v2 uses field_project_summary. In v3 config it is field_project_description.
    $summary = $this->getProjectSummary($entity);

    $finalTitle = $edoovillagePrefix . ": " . $summary;
    $entity->setTitle($finalTitle);
  }

  /**
   * Helper to allocate a new ID atomically.
   *
   * @return int
   *   The new ID.
   */
  private function allocateNewId(): int {
    /** @var \Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface $edoovillageRepository */
    $edoovillageRepository = \Drupal::service('labdoo_edoovillage.repository');
    $id = $edoovillageRepository->generateId();
    $edoovillageRepository->commit();
    return $id;
  }

  /**
   * Given a country code, it returns the name of the country.
   *
   * Replicated from labdoo_lib_country_code2name.
   *
   * @param string $countryCode
   *   Country code.
   *
   * @return string
   *   Country name.
   */
  private function countryCodeToName(string $countryCode): string {
    $countryManager = \Drupal::service('country_manager');
    $countries = $countryManager->getList();
    if (isset($countries[strtoupper($countryCode)])) {
      return (string) $countries[strtoupper($countryCode)];
    }
    return "[country not defined]";
  }

  /**
   * Gets the project summary from the entity.
   *
   * Replicated from labdoo_lib_get_field logic.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The project summary.
   */
  private function getProjectSummary(EntityInterface $entity): string {
    if ($entity->hasField('field_project_description') && !$entity->get('field_project_description')->isEmpty()) {
      return $entity->get('field_project_description')->value;
    }
    elseif ($entity->hasField('field_project_summary') && !$entity->get('field_project_summary')->isEmpty()) {
      return $entity->get('field_project_summary')->value;
    }
    return '';
  }

}
