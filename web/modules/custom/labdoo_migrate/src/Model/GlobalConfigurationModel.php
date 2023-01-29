<?php

namespace Drupal\labdoo_migrate\Model;

/**
 * DTO for global configuration.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class GlobalConfigurationModel {

  /**
   * The configuration array.
   *
   * @var array
   */
  private array $configuration;

  /**
   * GlobalConfigurationModel constructor.
   *
   * @param array $configuration
   *   The configuration array.
   */
  public function __construct(array $configuration) {

    $this->configuration = $configuration;
  }

  /**
   * Retrieves the files' configuration.
   *
   * @return array
   *   Returns the files' configuration.
   */
  public function getFilesConfig(): array {

    return $this->configuration['files_config'];
  }

  /**
   * Retrieves the content translation configuration.
   *
   * @return array
   *   Returns the content translation configuration.
   */
  public function getContentTranslationConfig(): array {

    return $this->configuration['content_translation'];
  }

  /**
   * Retrieves the dynamic paragraphs source configuration.
   *
   * @return array
   *   Returns the dynamic paragraphs source configuration.
   */
  public function getDynamicParagraphsSourceConfig(): array {

    return $this->configuration['dynamic_paragraphs']['source_data'];
  }

  /**
   * Retrieves the dynamic paragraphs mapping configuration.
   *
   * @return array
   *   Returns the dynamic paragraphs mapping configuration.
   */
  public function getDynamicParagraphsMappingConfig(): array {

    return $this->configuration['dynamic_paragraphs']['mapping'];
  }

}
