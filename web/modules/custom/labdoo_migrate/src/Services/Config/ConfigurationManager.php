<?php

namespace Drupal\labdoo_migrate\Services\Config;

use Drupal\Core\Extension\ModuleHandler;
use Drupal\labdoo_migrate\Model\ContentConfigurationModel;
use Drupal\labdoo_migrate\Model\GlobalConfigurationModel;

/**
 * The configuration manager.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ConfigurationManager implements ConfigurationManagerInterface {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  private ModuleHandler $moduleHandler;

  /**
   * The content configuration bag.
   *
   * @var \Drupal\labdoo_migrate\Services\Config\ConfigurationBagInterface
   */
  private ConfigurationBagInterface $contentConfigurationBag;

  /**
   * The global configuration bag.
   *
   * @var \Drupal\labdoo_migrate\Services\Config\ConfigurationBagInterface
   */
  private ConfigurationBagInterface $globalConfigurationBag;

  /**
   * ConfigurationManager constructor.
   *
   * @param \Drupal\Core\Extension\ModuleHandler $moduleHandler
   *   The module handler.
   */
  public function __construct(ModuleHandler $moduleHandler) {

    $this->moduleHandler = $moduleHandler;
    $this->contentConfigurationBag = ContentConfigurationBag::getInstance();
    $this->globalConfigurationBag = GlobalConfigurationBag::getInstance();
  }

  /**
   * {@inheritDoc}
   */
  public function getContentConfiguration(string $contentType): ContentConfigurationModel {

    $configData = $this->contentConfigurationBag->getData($this->getContentConfigPath($contentType));

    return new ContentConfigurationModel($configData);
  }

  /**
   * {@inheritDoc}
   */
  public function getGlobalConfiguration(): GlobalConfigurationModel {

    $configData = $this->globalConfigurationBag->getData($this->getGlobalConfigPath());

    return new GlobalConfigurationModel($configData);
  }

  /**
   * Retrieves the content configuration path.
   *
   * @param string $contentType
   *   The content type.
   *
   * @return string
   *   Returns the content configuration path.
   *
   * @throws \Exception
   */
  protected function getContentConfigPath(string $contentType): string {

    $modulePath = $this->moduleHandler->getModule('labdoo_migrate')->getPath();

    return sprintf('%s/config/fields_mapping/%s.json', $modulePath, strtolower($contentType));
  }

  /**
   * Retrieve the global configuration path.
   *
   * @return string
   *   Returns the global configuration path
   *
   * @throws \Exception
   */
  protected function getGlobalConfigPath(): string {

    $modulePath = $this->moduleHandler->getModule('labdoo_migrate')->getPath();

    return sprintf('%s/config/fields_mapping/%s.json', $modulePath, 'global');
  }

}
