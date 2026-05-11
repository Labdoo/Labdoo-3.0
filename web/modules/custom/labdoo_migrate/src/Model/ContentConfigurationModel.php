<?php

namespace Drupal\labdoo_migrate\Model;

/**
 * DTO for content configuration.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ContentConfigurationModel {

  /**
   * The configuration array.
   *
   * @var array
   */
  private array $configuration;

  /**
   * ContentConfigurationModel constructor.
   *
   * @param array $configuration
   *   The configuration array.
   */
  public function __construct(array $configuration) {

    $this->configuration = $configuration;
  }

  /**
   * Retrieves the destination types.
   *
   * @return array
   *   Returns the destination types.
   */
  public function getDestinationTypes(): array {

    return $this->configuration['destination_types'];
  }

  /**
   * Retrieves the entity type.
   *
   * @return string
   *   Returns the entity type.
   */
  public function getEntityType(): string {

    return $this->configuration['entity_type'];
  }

  /**
   * Retrieves the fields mapping.
   *
   * @return array
   *   Returns the fields mapping.
   */
  public function getFieldsMapping(): array {

    return $this->configuration['fields_mapping'];
  }

  /**
   * Retrieves the integrity fields.
   *
   * @return array
   *   Returns the integrity fields.
   */
  public function getIntegrityFields(): array {

    return $this->configuration['integrity_fields'] ?? [];
  }

}
