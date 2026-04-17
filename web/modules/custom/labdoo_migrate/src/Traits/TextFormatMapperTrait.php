<?php

namespace Drupal\labdoo_migrate\Traits;

/**
 * Trait for mapping Drupal 7 text formats to Drupal 10.
 */
trait TextFormatMapperTrait {

  /**
   * Maps Drupal 7 text formats to Drupal 10 text formats.
   *
   * @param string|null $format
   *   The source format.
   *
   * @return string
   *   The destination format.
   */
  protected function mapFormat(?string $format): string {
    return match ($format) {
      'full_html', 'filtered_html_advanced' => 'full_html',
      'filtered_html', 'basic_html' => 'basic_html',
      'plain_text', 'php_code' => 'plain_text',
      default => 'full_html',
    };
  }

}
