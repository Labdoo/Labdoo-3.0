<?php

namespace Drupal\labdoo_gallery\Services;

use Drupal\Core\Entity\EntityInterface;

/**
 * The Labdoo Gallery repository interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface LabdooGalleryRepositoryInterface {

  /**
   * Loads a gallery by its ID.
   *
   * @param int $id
   *   The gallery ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The gallery entity, or NULL if not found.
   */
  public function loadById(int $id): ?EntityInterface;

  /**
   * Loads an entity by its parent ID.
   *
   * @param int $parentId
   *   The parent entity ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity associated with the parent ID, or NULL if not found.
   */
  public function loadByParentId(int $parentId): ?EntityInterface;

  /**
   * Creates a new gallery.
   *
   * @param array $data
   *   The gallery data.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The created gallery entity, or NULL if creation failed.
   */
  public function create(array $data): ?EntityInterface;

  /**
   * Updates an existing gallery.
   *
   * @param int $id
   *   The gallery ID.
   * @param array $data
   *   The gallery data to update.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The updated gallery entity, or NULL if update failed.
   */
  public function update(int $id, array $data): ?EntityInterface;

}