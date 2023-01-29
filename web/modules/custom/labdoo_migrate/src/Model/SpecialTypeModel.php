<?php

namespace Drupal\labdoo_migrate\Model;

/**
 * DTO for a special type.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialTypeModel {

  /**
   * The type.
   *
   * @var string
   */
  private string $type;

  /**
   * The metadata array.
   *
   * @var array|null
   */
  private ?array $metadata;

  /**
   * SpecialTypeModel constructor.
   *
   * @param string $type
   *   The type.
   * @param array|null $metadata
   *   The metadata array.
   */
  public function __construct(
    string $type,
    array $metadata = NULL
  ) {

    $this->type = $type;
    $this->metadata = $metadata;
  }

  /**
   * Retrieves the type.
   *
   * @return string
   *   Returns the type.
   */
  public function getType(): string {

    return $this->type;
  }

  /**
   * Retrieves the metadata array.
   *
   * @return array
   *   Returns the metadata array.
   */
  public function getMetadata(): array {

    return $this->metadata ?? [];
  }

}
