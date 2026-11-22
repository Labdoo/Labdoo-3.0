<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\taxonomy\TermInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepositoryInterface;

/**
 * The special field type for taxonomy terms.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeTaxonomy extends AbstractSpecialFieldTypeRelatedEntity {

  /**
   * The translation repository.
   *
   * @var \Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepositoryInterface
   */
  protected TranslationRepositoryInterface $translationRepository;

  /**
   * SpecialFieldTypeTaxonomy constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepositoryInterface $translationRepository
   *   The trnaslation repository.
   *
   * @throws \Exception
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    TranslationRepositoryInterface $translationRepository,
  ) {

    parent::__construct($entityTypeManager);
    $this->translationRepository = $translationRepository;
    $this->bundle = 'taxonomy_term';
    $this->entityIdKey = 'vid';
    $this->entityValueKey = 'name';
    $this->langCodeKey = 'langcode';
  }

  /**
   * Checks if the entity exists.
   *
   * @param mixed $value
   *   The value.
   * @param array $metadata
   *   The metadata array.
   * @param string|null $langCode
   *   The language code.
   * @param string|null $mainLangCode
   *   The main language code.
   *
   * @return false|int|string|array
   *   Returns the entity (or entities) if it exits, otherwise FALSE.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function entityExists(
    $value,
    array $metadata,
    ?string $langCode,
    ?string $mainLangCode,
  ) {

    if (!is_array($value)) {
      return parent::entityExists($value, $metadata, NULL, $mainLangCode);
    }

    // We are creating new terms at this point.
    if (!isset($value['original_translation'])) {
      $termIds = [];
      foreach ($value as $term) {
        $termId = parent::entityExists($term, $metadata, NULL, $mainLangCode);
        if (!$termId) {
          $termId = $this->saveEntity($term, $metadata, $langCode);
        }
        $termIds[] = $termId;
      }

      return $termIds;
    }

    if (!($mainTerm = $this->getMainTerm($value['original_translation'], $metadata, $mainLangCode))) {
      return FALSE;
    }

    $isTranslation = $value['langcode'] !== $mainLangCode;
    if (!$isTranslation) {
      return $mainTerm->id();
    }

    return $this->getNewTranslation($mainTerm, $value['value'], $langCode);
  }

  /**
   * Retrieves the main term entity.
   *
   * @param mixed $value
   *   The value.
   * @param array $metadata
   *   The metadata array.
   * @param string $mainLangCode
   *   The main language code.
   *
   * @return \Drupal\taxonomy\TermInterface|false
   *   Returns the main term entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function getMainTerm($value, array $metadata, string $mainLangCode): TermInterface|false {

    $results = $this->entityTypeManager
      ->getStorage($this->bundle)
      ->loadByProperties([
        $this->entityIdKey => $metadata['type_id'],
        $this->entityValueKey => $value,
        $this->langCodeKey => $mainLangCode,
      ]);
    if (!$results) {
      return FALSE;
    }

    $mainTerm = reset($results);
    if (!$mainTerm instanceof TermInterface) {
      throw new \UnexpectedValueException('Taxonomy storage returned an unsupported entity.');
    }
    return $mainTerm;
  }

  /**
   * Retrieves the new translation entity.
   *
   * @param \Drupal\taxonomy\TermInterface $mainTerm
   *   The main term entity.
   * @param string $value
   *   The value.
   * @param string $langCode
   *   The language code.
   *
   * @return mixed
   *   Returns the new translation entity.
   */
  protected function getNewTranslation(
    TermInterface $mainTerm,
    string $value,
    string $langCode,
  ) {

    $translation = $this->translationRepository->getEntityTranslation(
      $mainTerm,
      $langCode
    );
    $translation->set($this->entityValueKey, $value);

    return $translation->id();
  }

  /**
   * Saves the entity.
   *
   * @param mixed $value
   *   The value.
   * @param array $metadata
   *   The metadata array.
   * @param string $langCode
   *   The language code.
   *
   * @return int|string|null
   *   Returns the entity ID or NULL if no value is provided.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function saveEntity($value, array $metadata, string $langCode) {

    if (!$value) {
      return NULL;
    }

    $value = $value['value'] ?? $value;

    $entity = $this->entityTypeManager
      ->getStorage($this->bundle)
      ->create([
        $this->entityIdKey => $metadata['type_id'],
        $this->entityValueKey => $value,
        $this->langCodeKey => $langCode,
      ]);
    $entity->save();

    return $entity->id();
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
