<?php

namespace Drupal\labdoo_migrate\Services\Database;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;

/**
 * External connection manager.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ExternalConnectionManager implements ConnectionManagerInterface {

  /**
   * The database key.
   *
   * For this class to work, an external database must be defined at
   * settings.php or settings.local.php:
   *
   * $databases['external']['default'] = array(
   *   'database' => 'database',
   *   'username' => 'username',
   *   'password' => 'password',
   *   'prefix' => '',
   *   'host' => 'localhost',
   *   'port' => '3306',
   *   'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
   *   'driver' => 'mysql',
   * );
   */
  private const DB_KEY = 'external';

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $connection;

  /**
   * The default key.
   *
   * @var string|null
   */
  private ?string $defaultKey = null;

  /**
   * {@inheritDoc}
   */
  public function setConnection(): Connection {

    if (!isset($this->connection)) {
      $this->defaultKey = Database::setActiveConnection(self::DB_KEY);
      $this->connection = Database::getConnection(self::DB_KEY);
    }

    return $this->connection;
  }

  /**
   * {@inheritDoc}
   */
  public function restoreConnection() {

    Database::setActiveConnection($this->defaultKey);
  }

}
