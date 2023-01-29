<?php

namespace Drupal\labdoo_migrate\Services\DynamicContent;

use Drupal\labdoo_migrate\Model\FieldModel;

/**
 * The source content repository interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface DynamicContentRepositoryInterface {

  /**
   * Retrieves the paragraph value.
   *
   * @param int $entityId
   *   The entity ID.
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   *
   * @return array|false|int
   *   Returns the paragraph value(s) or FALSE if not available.
   *
   * @throws \Exception
   */
  public function getValue(int $entityId, FieldModel $field);

}
