<?php

namespace Drupal\labdoo_migrate\Services\Config;

use Drupal\labdoo_migrate\Model\ContentConfigurationModel;
use Drupal\labdoo_migrate\Model\GlobalConfigurationModel;

/**
 * The configuration manager interface.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface ConfigurationManagerInterface {

  /**
   * Retrieves the content configuration.
   *
   * @param string $contentType
   *   The content type.
   *
   * @return \Drupal\labdoo_migrate\Model\ContentConfigurationModel
   *   Retrieves the content configuration.
   *
   * @throws \Exception
   */
  public function getContentConfiguration(string $contentType): ContentConfigurationModel;

  /**
   * Retrieves the global configuration.
   *
   * @return \Drupal\labdoo_migrate\Model\GlobalConfigurationModel
   *   Retrieves the global configuration.
   *
   * @throws \Exception
   */
  public function getGlobalConfiguration(): GlobalConfigurationModel;

}
