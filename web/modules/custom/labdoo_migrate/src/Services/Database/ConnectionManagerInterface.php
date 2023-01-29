<?php

namespace Drupal\labdoo_migrate\Services\Database;

use Drupal\Core\Database\Connection;

/**
 * Connection manager interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface ConnectionManagerInterface {

  /**
   * Sets a new connection.
   *
   * @return \Drupal\Core\Database\Connection
   *   Returns the connection.
   */
  public function setConnection(): Connection;

  /**
   * Restores the connection to the default one.
   */
  public function restoreConnection();

}
