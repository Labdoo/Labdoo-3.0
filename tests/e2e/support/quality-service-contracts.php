<?php

/**
 * @file
 * Checks the changed service and plugin factories without executing their jobs.
 */

declare(strict_types=1);

require __DIR__ . '/assert-isolated.php';

$container = Drupal::getContainer();
$ids = [
  'labdoo_dootrip.compute',
  'labdoo_edoovillage.compute',
  'labdoo_edoovillage.repository',
  'labdoo_hub.compute',
  'labdoo_hub.repository',
  'labdoo_notifications.notification_manager',
  'labdoo_notifications.email_processor',
  'labdoo_gallery.compute',
  'labdoo_user.path_processor',
  'labdoo_migrate.repository_registry',
];
foreach ($ids as $id) {
  if (!$container->has($id) || !is_object($container->get($id))) {
    throw new RuntimeException('A changed service could not be constructed: ' . $id);
  }
}
$registry = $container->get('labdoo_migrate.repository_registry');
foreach (['node', 'user', 'comment'] as $type) {
  if (!$registry->source($type) instanceof Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface
    || !$registry->destination($type) instanceof Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface) {
    throw new RuntimeException('A migration repository has an incompatible contract.');
  }
}
$blocks = [
  'dootrip_chart_block_block',
  'dootronics_chart_block_block',
  'edoovillage_chart_block_block',
  'hub_chart_block_block',
  'labdoo_map_block',
  'labdoo_footer_acts_block',
];
foreach ($blocks as $id) {
  $block = $container->get('plugin.manager.block')->createInstance($id);
  if ($block->getPluginId() !== $id) {
    throw new RuntimeException('A changed block factory returned the wrong plugin.');
  }
}
foreach ([
  Drupal\mini_wiki\Form\MiniWikiPageSettingsForm::class,
  Drupal\labdoo_user\Form\UserRolesForm::class,
  Drupal\labdoo_notifications\Form\TestEmailSettingsForm::class,
] as $class) {
  if (!$class::create($container) instanceof $class) {
    throw new RuntimeException('A changed form factory returned an incompatible form.');
  }
}
echo 'QUALITY_SERVICES_JSON:' . json_encode([
  'services' => count($ids),
  'repository_pairs' => 3,
  'blocks' => count($blocks),
  'forms' => 3,
], JSON_THROW_ON_ERROR);
