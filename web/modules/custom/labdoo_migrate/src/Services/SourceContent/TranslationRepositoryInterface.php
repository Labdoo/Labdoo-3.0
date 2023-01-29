<?php

namespace Drupal\labdoo_migrate\Services\SourceContent;

use Drupal\labdoo_migrate\Model\FieldModel;

/**
 * The translation repository interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface TranslationRepositoryInterface {

  /**
   * Retrieves the language code of an entity.
   *
   * @param mixed $entityId
   *   The entity ID.
   *
   * @return string
   *   Returns the language code of the given entity.
   *
   * @throws \Exception
   */
  public function getLangCode($entityId): string;

  /**
   * Retrieves the translations of an entity.
   *
   * @param mixed $entityId
   *   The entity ID.
   * @param string $mainLangCode
   *   The main language code.
   *
   * @return array
   *   Returns an array with the translations of the given entity.
   *
   * @throws \Exception
   */
  public function getTranslations($entityId, string $mainLangCode): array;

  /**
   * Retrieves the original translation of an entity.
   *
   * @param int $entityId
   *   The entity ID.
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   * @param string $mainLangCode
   *   The main language code.
   *
   * @return string
   *   Returns the original translation of the given entity.
   *
   * @throws \Exception
   */
  public function getOriginalTranslation(
    int $entityId,
    FieldModel $field,
    string $mainLangCode
  ): string;

}
