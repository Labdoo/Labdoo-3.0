<?php

declare(strict_types=1);

namespace Drupal\labdoo_translations;

use Drupal\Core\Menu\MenuLinkManager;
use Drupal\labdoo_translations\Plugin\Menu\CatalogMenuLinkContent;
use Drupal\menu_link_content\Plugin\Menu\MenuLinkContent;

/** Applies the content-link fallback to runtime, including undiscovered links. */
class CatalogMenuLinkManager extends MenuLinkManager {

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    $definition = parent::getDefinition($plugin_id, $exception_on_invalid);
    // External content links have rediscover=FALSE and never reach discovery
    // alters. Change only the returned copy; do not persist translated labels.
    if (is_array($definition) && is_string($definition['class'] ?? NULL) && ltrim($definition['class'], '\\') === MenuLinkContent::class) {
      $definition['class'] = CatalogMenuLinkContent::class;
    }
    return $definition;
  }

}
