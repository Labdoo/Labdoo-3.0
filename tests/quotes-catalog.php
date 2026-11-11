<?php

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\labdoo_quotes\QuoteCatalog;

$module = DRUPAL_ROOT . '/' . \Drupal::service('extension.list.module')->getPath('labdoo_quotes');
$source = json_decode(file_get_contents($module . '/quotes.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$files = glob($module . '/translations/labdoo_quotes.*.po');
if (count($files) !== 20 || count($source) !== 6) {
  throw new RuntimeException('Expected six quotes in twenty PO catalogs.');
}
$before = \Drupal::state()->get('locale.translation.plurals');
\Drupal::service('labdoo_quotes.importer')->import();
if ($before !== \Drupal::state()->get('locale.translation.plurals')) {
  throw new RuntimeException('Quote imports must not alter global plural rules.');
}
$results = [];
foreach ($files as $file) {
  preg_match('/labdoo_quotes\.(.+)\.po$/', $file, $match);
  $langcode = $match[1];
  $reader = new PoStreamReader();
  $reader->setURI($file);
  $reader->setLangcode($langcode);
  $reader->open();
  if (preg_match('/^"Plural-Forms:/m', file_get_contents($file))) {
    throw new RuntimeException('Singular quote catalogs must not contain global plural metadata.');
  }
  $expected = [];
  while ($item = $reader->readItem()) {
    if ($item->getContext() !== QuoteCatalog::CONTEXT || $item->isPlural() || $item->getTranslation() === '') {
      throw new RuntimeException('Invalid or empty quote translation: ' . $langcode);
    }
    if (isset($expected[$item->getSource()])) {
      throw new RuntimeException('Duplicate PO source: ' . $langcode);
    }
    $expected[$item->getSource()] = $item->getTranslation();
  }
  $reader->close();
  if (count($expected) !== 12) {
    throw new RuntimeException('Every quote and author needs a PO entry: ' . $langcode);
  }
  $translated = \Drupal::service('labdoo_quotes.catalog')->all($langcode);
  foreach ($source as $index => $quote) {
    foreach (['text', 'author'] as $key) {
      if ($translated[$index][$key] !== $expected[$quote[$key]]) {
        throw new RuntimeException('Drupal translation differs from PO: ' . $langcode . '/' . $quote['id'] . '/' . $key);
      }
    }
    if ($langcode !== 'en' && $translated[$index]['text'] === $quote['text']) {
      throw new RuntimeException('Untranslated quote: ' . $langcode . '/' . $quote['id']);
    }
  }
  $results[$langcode] = count($translated);
}
echo json_encode(['languages' => $results, 'plural_rules_unchanged' => TRUE], JSON_PRETTY_PRINT) . PHP_EOL;
