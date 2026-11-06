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
   *   Entity with field parent.
   *
   * @return array|null
   *   Label and URL to the entity referenced by field parent.
   */
  public function getParentEntityUrl(EntityInterface $entity): ?array {
    if ($entity->hasField('parent') && !$entity->get('parent')->isEmpty()) {
      $parentEntity = $entity->get('parent')->entity;
      if ($parentEntity && $parentEntity->access('view')) {
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
    $query->orderBy('fd.weight');
    $query->orderBy('fd.label');
    $query->orderBy('fd.id');
    if (!\Drupal::currentUser()->hasPermission('edit mini_wiki_page') && !\Drupal::currentUser()->hasPermission('administer mini_wiki_page')) {
      $query->condition('fd.status', 1);
    }
    $query->condition('fd.langcode', $this->languageManager->getCurrentLanguage()->getId());
    try {
      $results = $query->execute()->fetchAll();
    }
    catch (\Exception $e) {
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

  /**
   * Finds the previous and next readable pages in book order.
   */
  public function getAdjacentPages(EntityInterface $entity): array {
    $parent_id = $entity->get('parent')->target_id;
    $siblings = $parent_id ? $this->loadVisibleChildren($parent_id) : [$entity];
    $ids = array_map(static fn(EntityInterface $page) => (string) $page->id(), $siblings);
    $position = array_search((string) $entity->id(), $ids, TRUE);
    $previous = NULL;
    if ($position !== FALSE && $position > 0) {
      $previous = $siblings[$position - 1];
      $seen = [(string) $entity->id() => TRUE];
      while (!isset($seen[(string) $previous->id()])) {
        $seen[(string) $previous->id()] = TRUE;
        $children = $this->loadVisibleChildren($previous->id());
        if (!$children) {
          break;
        }
        $last = end($children);
        if (isset($seen[(string) $last->id()])) {
          break;
        }
        $previous = $last;
      }
    }
    else {
      $parent = $entity->get('parent')->entity;
      if ($parent && $parent->access('view')) {
        $previous = $parent;
      }
    }

    $children = $this->loadVisibleChildren($entity->id());
    $next = $children[0] ?? NULL;
    $page = $entity;
    $seen = [];
    while (!$next && $page && !isset($seen[(string) $page->id()])) {
      $seen[(string) $page->id()] = TRUE;
      if (!$page->get('parent')->target_id) {
        break;
      }
      $siblings = $this->loadVisibleChildren($page->get('parent')->target_id);
      $ids = array_map(static fn(EntityInterface $sibling) => (string) $sibling->id(), $siblings);
      $position = array_search((string) $page->id(), $ids, TRUE);
      if ($position !== FALSE && isset($siblings[$position + 1])) {
        $next = $siblings[$position + 1];
        break;
      }
      $page = $page->get('parent')->entity;
      if ($page && !$page->access('view')) {
        break;
      }
    }

    return ['previous' => $previous, 'next' => $next];
  }

  /**
   * Loads readable siblings in their configured order and current language.
   */
  protected function loadVisibleChildren(?string $parent_id): array {
    $storage = $this->entityTypeManager->getStorage('mini_wiki_page');
    $query = $storage->getQuery()->accessCheck(TRUE);
    if ($parent_id) {
      $query->condition('parent', $parent_id);
    }
    else {
      $query->notExists('parent');
    }
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $ids = $query->condition('langcode', $langcode)
      ->sort('weight')->sort('label')->sort('id')->execute();
    $pages = [];
    foreach ($storage->loadMultiple($ids) as $page) {
      if ($page->hasTranslation($langcode)) {
        $page = $page->getTranslation($langcode);
      }
      if ($page->access('view')) {
        $pages[] = $page;
      }
    }
    return $pages;
  }

}
