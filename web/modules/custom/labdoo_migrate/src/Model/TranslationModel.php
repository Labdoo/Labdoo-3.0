<?php

namespace Drupal\labdoo_migrate\Model;

/**
 * DTO for a translation.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class TranslationModel {

  /**
   * The ID.
   *
   * @var string
   */
  private string $id;

  /**
   * The langcode.
   *
   * @var string
   */
  private string $langCode;

  /**
   * TranslationModel constructor.
   *
   * @param string $id
   *   The ID.
   * @param string $langCode
   *   The language code.
   */
  public function __construct(
    string $id,
    string $langCode
  ) {

    $this->id = $id;
    $this->langCode = $langCode;
  }

  /**
   * Retrieves the ID.
   *
   * @return string
   *   Returns the ID.
   */
  public function getId(): string {

    return $this->id;
  }

  /**
   * Retrieves the language code.
   *
   * @return string
   *   Returns the language code.
   */
  public function getLangCode(): string {

    return $this->langCode;
  }

}
