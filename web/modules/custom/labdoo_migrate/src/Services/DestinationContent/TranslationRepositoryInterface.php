<?php

namespace Drupal\labdoo_migrate\Services\DestinationContent;

use Drupal\Core\Entity\EntityInterface;

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
   * Tells if the translations have to be created instead of found.
   *
   * @return bool
   *   Whether missing translations should be created.
   */
  public function isCreateTranslations(): bool;

  /**
   * Retrieves a translation of an entity given a language code.
   *
   * @param T $entity
   *   The entity.
   * @param string $langCode
   *   The given language code.
   *
   * @return T
   *   Returns the translation entity.
   *
   * @template T of \Drupal\Core\Entity\EntityInterface
   */
  public function getEntityTranslation(
    EntityInterface $entity,
    string $langCode,
  ): EntityInterface;

  /**
   * Checks if an entity has a given translation.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $langCode
   *   The language code.
   *
   * @return bool
   *   Returns TRUE if the entity has the given translation, otherwise FALSE.
   */
  public function hasEntityTranslation(
    EntityInterface $entity,
    string $langCode,
  ): bool;

}
