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
    $extractedId = NULL;

    // Try to extract ID from current title.
    if (preg_match('/Edoovillage\s+#(\d+)/i', $title, $matches)) {
      $extractedId = (int) $matches[1];
    }

    if (!$entity->isNew()) {
      // This is an update of an existing edoovillage.
      $originalTitle = isset($entity->original) ? $entity->original->getTitle() : '';

      // Try to extract ID from original title.
      $originalId = NULL;
      if (preg_match('/Edoovillage\s+#(\d+)/i', $originalTitle, $matches)) {
        $originalId = (int) $matches[1];
      }

      if ($originalId !== NULL) {
        // The original title was standard, so we MUST keep the ID regardless of current title.
        $edoovillagePrefix = "Edoovillage #" . $originalId . " - ";
      }
      elseif ($extractedId !== NULL) {
        // The original was legacy but the user is trying to make it standard by adding an ID.
        $edoovillagePrefix = "Edoovillage #" . $extractedId . " - ";
      }
      else {
        // Check if the current title starts with "Edoovillage" even without ID.
        // If it does, we should probably assign an ID if it's missing.
        if (stripos(trim($title), 'Edoovillage') === 0) {
          $edoovillagePrefix = "Edoovillage #" . $this->allocateNewId() . " - ";
        }
        else {
          // Support legacy naming convention for older edoovillages (name without IDs).
          $edoovillagePrefix = "";
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

    if (empty($edoovillagePrefix)) {
      // For legacy titles, we want to ensure we don't accidentally mess up the title
      // if it was manually edited to something completely different.
      $finalTitle = $title;
      if (mb_strlen($finalTitle) > 255) {
        $finalTitle = mb_substr($finalTitle, 0, 252) . '...';
      }
    }
    else {
      // If we have a prefix (Edoovillage #ID - Country, City), we concatenate the summary.
      // We also want to make sure the summary isn't already part of the title
      // in a way that would cause double concatenation, although setEdooVillageTitle
      // usually replaces the whole title.
      $prefixWithSeparator = $edoovillagePrefix . ": ";
      $finalTitle = $prefixWithSeparator . $summary;

      // Drupal titles have a 255 character limit.
      if (mb_strlen($finalTitle) > 255) {
        $summary = mb_substr($summary, 0, 255 - mb_strlen($prefixWithSeparator) - 3) . '...';
        $finalTitle = $prefixWithSeparator . $summary;
      }
    }

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
