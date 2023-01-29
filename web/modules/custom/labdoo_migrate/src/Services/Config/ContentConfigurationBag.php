<?php

namespace Drupal\labdoo_migrate\Services\Config;

/**
 * The content configuration bag.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ContentConfigurationBag implements ConfigurationBagInterface {

  /**
   * The singleton's instances.
   *
   * @var array
   */
  private static array $instances = [];

  /**
   * The configuration data.
   *
   * @var array
   */
  private array $configData;

  /**
   * The Singleton's constructor should always be private.
   */
  protected function __construct() {
  }

  /**
   * Singletons should not be cloneable.
   */
  protected function __clone() {
  }

  /**
   * {@inheritDoc}
   */
  public function __wakeup() {

    throw new \Exception("Cannot unserialize a singleton.");
  }

  /**
   * {@inheritDoc}
   */
  public static function getInstance(): ContentConfigurationBag {

    $currentInstance = static::class;
    if (!isset(self::$instances[$currentInstance])) {
      self::$instances[$currentInstance] = new static();
    }

    return self::$instances[$currentInstance];
  }

  /**
   * {@inheritDoc}
   */
  public function getData(string $configPath): array {

    if (isset($this->configData)) {
      return $this->configData;
    }

    $this->configData = $this->checkData($this->readData($configPath));

    return $this->configData;
  }

  /**
   * Reads the configuration file.
   *
   * @param string $configPath
   *   The configuration file path.
   *
   * @return string
   *   Returns the configuration.
   *
   * @throws \Exception
   */
  protected function readData(string $configPath): string {

    if (!file_exists($configPath)) {
      $errorMessage = sprintf('Wrong configuration path: %s', $configPath);
      throw new \Exception($errorMessage);
    }

    return file_get_contents($configPath);
  }

  /**
   * Checks the configuration data.
   *
   * @param string $configData
   *   The configuration data.
   *
   * @return array
   *   Returns the configuration data in case there are no errors.
   *
   * @throws \Exception
   */
  protected function checkData(string $configData): array {

    $configData = json_decode($configData, TRUE);
    if (!$configData) {
      throw new \Exception('Wrong configuration data');
    }

    if (
      empty($configData['destination_types'])
      || empty($configData['fields_mapping'])
    ) {
      throw new \Exception('Wrong content configuration data');
    }

    return $configData;
  }

}
