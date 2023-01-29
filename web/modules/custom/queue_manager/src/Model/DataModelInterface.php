<?php

namespace Drupal\queue_manager\Model;

/**
 * Interface for data DTOs.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface DataModelInterface {

  /**
   * Serialization method.
   *
   * @return array
   *   The serialized data.
   */
  public function __serialize(): array;

  /**
   * Deserialization method.
   *
   * @param null|array $data
   *   The structured data.
   *
   * @throws \Drupal\Component\Serialization\Exception\InvalidDataTypeException
   * @throws \Exception
   */
  public function __unserialize(?array $data);

}
