<?php

use Drupal\Core\Site\Settings;
use Drupal\labdoo_quotes\QuoteCatalog;

if (!Settings::get('labdoo_e2e') || !str_starts_with(\Drupal::database()->getConnectionOptions()['database'], 'labdoo_e2e_')) {
  throw new RuntimeException('This customization test requires an isolated E2E database.');
}
$source = 'The highest result of education is tolerance.';
$storage = \Drupal::service('locale.storage');
$record = $storage->findTranslation(['language' => 'es', 'source' => $source, 'context' => QuoteCatalog::CONTEXT]);
if (!$record) {
  throw new RuntimeException('Install quote translations before running this test.');
}
$original = $record->getString();
$customized = $record->customized;
$plurals = \Drupal::state()->get('locale.translation.plurals');
try {
  $record->setString('Traducción personalizada de prueba.');
  $record->customized = LOCALE_CUSTOMIZED;
  $record->save();
  \Drupal::service('labdoo_quotes.importer')->import(['es']);
  $after = $storage->findTranslation(['language' => 'es', 'source' => $source, 'context' => QuoteCatalog::CONTEXT]);
  if ($after->getString() !== 'Traducción personalizada de prueba.' || !$after->customized) {
    throw new RuntimeException('Bundled import overwrote a customized translation.');
  }
  if ($plurals !== \Drupal::state()->get('locale.translation.plurals')) {
    throw new RuntimeException('Bundled import changed plural metadata.');
  }
}
finally {
  $record->setString($original);
  $record->customized = $customized;
  $record->save();
  _locale_refresh_translations(['es'], [$record->lid]);
  \Drupal::service('string_translation')->reset();
}
echo json_encode(['customized_translation_preserved' => TRUE, 'plural_rules_preserved' => TRUE]) . PHP_EOL;
