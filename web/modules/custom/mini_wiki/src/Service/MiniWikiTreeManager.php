<?php

namespace Drupal\mini_wiki\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Url;

/**
 * Provides functions to build a wiki tree.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MiniWikiTreeManager {

  /**
   * MiniWikiTreeManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManager $languageManager
   *   The language manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManager $languageManager
  ) {
  }

  /**
   * Generates the link to the entity referenced by field parent.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *  Entity with field parent.
   *
   * @return array|null
   *   Label and URL to the entity referenced by field parent.
   */
  public function getParentEntityUrl(EntityInterface $entity): ?array {
    if ($entity->hasField('parent') && !$entity->get('parent')->isEmpty()) {
      $parentEntity = $entity->get('parent')->entity;
      if ($parentEntity) {
        $url = Url::fromRoute(
          'entity.mini_wiki_page.canonical',
          ['mini_wiki_page' => $parentEntity->id()]
        )->toString();

        return [
          'title' => $parentEntity->label(),
          'url' => $url,
        ];
      }
    }

    return NULL;
  }

  /**
   * Retrieves an array of links to all entities that have the given node as its field parent.
   *
   * @param int $entityId
   *   Node ID.
   *
   * @return array
   *   Array of links to entities that have the given node as its field parent.
   */
  public function getChildrenEntitiesUrls(int $entityId): array {
    $database = \Drupal::database();
    $query = $database->select('mini_wiki_page_field_data', 'fd');
    $query->fields('fd', ['id', 'label']);
    $query->condition('fd.parent', $entityId);
    $query->condition('fd.langcode', $this->languageManager->getCurrentLanguage()->getId());
    try {
      $results = $query->execute()->fetchAll();
    } catch (\Exception $e) {
      return [];
    }

    if (empty($results)) {
      return [];
    }

    $links = [];

    foreach ($results as $result) {
      $url = Url::fromRoute(
        'entity.mini_wiki_page.canonical',
        ['mini_wiki_page' => $result->id]
      )->toString();
      $links[] = [
        'title' => $result->label,
        'url' => $url,
      ];
    }

    return $links;
  }

}
