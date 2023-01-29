<?php

namespace Drupal\labdoo_migrate\Services\Mapper;

/**
 * The mapper interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface MapperInterface {

  /**
   * Builds the mapping.
   *
   * @param array $fieldsMapping
   *   The fields mapping.
   *
   * @return array
   *   Returns the mapping array.
   *
   * @throws \Exception
   */
  public function buildMapping(array $fieldsMapping): array;

}
