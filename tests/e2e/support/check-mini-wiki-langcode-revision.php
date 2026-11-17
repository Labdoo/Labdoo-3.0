<?php

declare(strict_types=1);

$field_manager = \Drupal::service('entity_field.manager');
$schema_repository = \Drupal::service('entity.last_installed_schema.repository');
$current = $field_manager->getFieldStorageDefinitions('mini_wiki_page')['langcode'];
$installed = $schema_repository->getLastInstalledFieldStorageDefinitions('mini_wiki_page')['langcode'];
$path = $field_manager->getFieldDefinitions('mini_wiki_page', 'mini_wiki_page')['path'];
$installed_path = $schema_repository->getLastInstalledFieldStorageDefinitions('mini_wiki_page')['path'] ?? NULL;
$schema_warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$schema_warnings): bool {
  if (str_contains($message, 'Undefined array key "fields"') || str_contains($message, 'foreach() argument must be of type array|object')) {
    $schema_warnings[] = $message;
    return TRUE;
  }

  return FALSE;
});
$change_list = \Drupal::entityDefinitionUpdateManager()->getChangeList();
restore_error_handler();
$update_pending = isset($change_list['mini_wiki_page']['field_storage_definitions']['langcode']);
$path_update_pending = isset($change_list['mini_wiki_page']['field_storage_definitions']['path']);

if (!$current->isRevisionable() || !$installed->isRevisionable() || $update_pending) {
  throw new RuntimeException('Mini wiki langcode must remain revisionable and match the installed schema.');
}
if (!$path->isComputed() || !$path->isTranslatable() || $installed_path || $path_update_pending || $schema_warnings) {
  throw new RuntimeException('The mini wiki path field must follow Drupal path-field storage and produce no schema warnings.');
}

printf("mini_wiki_langcode_revisionable=true\nmini_wiki_langcode_update_pending=false\nmini_wiki_path_computed=true\nmini_wiki_path_translatable=true\nmini_wiki_path_update_pending=false\nmini_wiki_schema_warnings=0\n");
