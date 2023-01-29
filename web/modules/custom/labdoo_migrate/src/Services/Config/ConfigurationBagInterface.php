<?php

namespace Drupal\labdoo_migrate\Services\Config;

/**
 * The configuration bag interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface ConfigurationBagInterface {

  /**
   * Singletons should not be restorable from strings.
   *
   * @throws \Exception
   */
  public function __wakeup();

  /**
   * Retrieves an instance of the singleton.
   *
   * @return \Drupal\labdoo_migrate\Services\Config\ConfigurationBagInterface
   *   Returns an instance of the singleton.
   */
  public static function getInstance(): ConfigurationBagInterface;

  /**
   * Retrieves the configuration data.
   *
   * @param string $configPath
   *   The configuration file path.
   *
   * @return mixed
   *   Returns the configuration data.
   *
   * @throws \Exception
   */
  public function getData(string $configPath);

}
