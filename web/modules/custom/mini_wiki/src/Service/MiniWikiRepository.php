<?php

namespace Drupal\mini_wiki\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mini_wiki\Entity\MiniWikiPage;

/**
 * Provides data-access functions.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MiniWikiRepository {

  protected const ENTITY_TYPE_ID = 'mini_wiki_page';

  /**
   * MiniWikiRepository constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager
  ) {
  }

  /**
   * Load one entity by given ID.
   *
   * @param int $id
   *   The ID of the entity to load.
   *
   * @return \Drupal\mini_wiki\Entity\MiniWikiPage
   *   The loaded entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function load(int $id): MiniWikiPage {
    /** @var \Drupal\mini_wiki\Entity\MiniWikiPage $entity */
    $entity = $this->entityTypeManager
      ->getStorage(self::ENTITY_TYPE_ID)
      ->load($id);

    return $entity;
  }

  /**
   * Returns data for all items created or modified from a given date.
   *
   * @param string $date
   *   The date parameter in 'Y-m-d' format
   *
   * @return array
   *   An array with the entities.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getModifiedEntities(string $date): array {
    $timestamp = strtotime($date);

    $query = $this->entityTypeManager
      ->getStorage(self::ENTITY_TYPE_ID)
      ->getQuery()
      ->accessCheck();
    $group = $query
      ->orConditionGroup()
      ->condition('changed', $timestamp, '>=')
      ->condition('created', $timestamp, '>=');
    $query->condition($group);
    $entityIds = $query
      ->sort('created', 'DESC')
      ->execute();

    return $this->entityTypeManager
      ->getStorage(self::ENTITY_TYPE_ID)
      ->loadMultiple($entityIds);
  }

  /**
   * Get data of a Wiki page.
   *
   * @param MiniWikiPage $miniWikiPage
   *   The MiniWikiPage object to get data from.
   *
   * @return array
   *   An array containing the Wiki page data:
   *   [
   *    'id' => The id of the Wiki page,
   *    'title' => The title of the Wiki page,
   *    'body' => The body content of the Wiki page,
   *    'tags' => The tags of the Wiki page,
   *    'uri' => The URI of the Wiki page,
   *    'author' => The author of the Wiki page,
   *    'created' => The creation date of the Wiki page,
   *    'changed' => The last modification date of the Wiki page,
   *   ]
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function buildData(MiniWikiPage $miniWikiPage): array {
    $tagsData = [];
    $tags = $miniWikiPage->get('field_tags');

    foreach ($tags as $tag) {
      $tagsData[] = $tag->entity?->label();
    }

    $bodyWithoutTags = $miniWikiPage->get('body')->value ?? '';
    if (!empty($bodyWithoutTags)) {
      $bodyWithoutTags = strip_tags(
        html_entity_decode($bodyWithoutTags)
      );
    }

    return [
      'id' => (int) $miniWikiPage->id(),
      'title' => $miniWikiPage->label(),
      'body' => $bodyWithoutTags,
      'tags' => $tagsData,
      'uri' => $miniWikiPage->toUrl()->toString(),
      'author' => $miniWikiPage->getOwner()->get('field_username')->value,
      'created' => (int) $miniWikiPage->get('created')->value,
      'changed' => (int) $miniWikiPage->get('changed')->value,
    ];
  }

}
