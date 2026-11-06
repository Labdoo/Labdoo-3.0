<?php

namespace Drupal\labdoo_e2e;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Site\Settings;

final class LabdooE2eServiceProvider extends ServiceProviderBase {
  public function alter(ContainerBuilder $container) {
    if (!Settings::get('labdoo_e2e', FALSE)) {
      throw new \RuntimeException('The E2E harness requires an explicitly isolated site.');
    }
    $container->getDefinition('geocoder')->setClass(FixtureGeocoder::class)->setArguments([]);
    if ($container->hasDefinition('labdoo_common.geocoder_decorator')) {
      $container->getDefinition('labdoo_common.geocoder_decorator')->setClass(FixtureGeocoder::class)->setArguments([]);
    }
    $container->getDefinition('plugin.manager.mail')->setClass(Mail\CollectorMailManager::class);
  }
}
