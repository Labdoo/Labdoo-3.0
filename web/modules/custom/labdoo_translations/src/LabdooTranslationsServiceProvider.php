<?php

declare(strict_types=1);

namespace Drupal\labdoo_translations;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Menu\MenuLinkManager;

/** Extends only Drupal's standard menu manager, preserving specialized managers. */
class LabdooTranslationsServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if (!$container->hasDefinition('plugin.manager.menu.link')) {
      return;
    }
    $definition = $container->getDefinition('plugin.manager.menu.link');
    if (ltrim((string) $definition->getClass(), '\\') === MenuLinkManager::class) {
      // The subclass inherits core's constructor and all services/operations.
      $definition->setClass(CatalogMenuLinkManager::class);
    }
  }

}
