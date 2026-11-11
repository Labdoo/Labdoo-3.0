<?php

namespace Drupal\labdoo_quotes;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Component\Gettext\PoStreamReader;
use Drupal\locale\PoDatabaseWriter;

/**
 * Imports bundled PO files while retaining customized interface translations.
 */
final class TranslationImporter {

  /**
   * Constructs the local-only translation importer.
   */
  public function __construct(
    private readonly ModuleExtensionList $modules,
    private readonly LanguageManagerInterface $languages,
    private readonly TranslationInterface $translation,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Imports translations for installed languages, without changing plural rules.
   */
  public function import(?array $langcodes = NULL): array {
    if (!$this->lock->acquire('labdoo_quotes.import', 60)) {
      throw new \RuntimeException('A quote translation import is already running.');
    }
    $reports = [];
    $lids = [];
    try {
      $installed = $this->languages->getLanguages();
      foreach ($langcodes ?? array_keys($installed) as $langcode) {
        $uri = DRUPAL_ROOT . '/' . $this->modules->getPath('labdoo_quotes') . '/translations/labdoo_quotes.' . $langcode . '.po';
        if ($langcode === 'en' || !isset($installed[$langcode]) || !is_file($uri)) {
          continue;
        }
        $reader = new PoStreamReader();
        $reader->setURI($uri);
        $reader->setLangcode($langcode);
        $reader->open();
        $writer = new PoDatabaseWriter();
        $writer->setLangcode($langcode);
        $writer->setOptions([
          'overwrite_options' => ['not_customized' => TRUE, 'customized' => FALSE],
          'customized' => LOCALE_NOT_CUSTOMIZED,
        ]);
        // Do not pass the PO header to the database writer: even an absent
        // Plural-Forms header gets a default in Drupal's PO reader. This module
        // owns singular quote strings only, never site-wide plural formulas.
        try {
          while ($item = $reader->readItem()) {
            if ($item->isPlural() || $item->getContext() !== QuoteCatalog::CONTEXT) {
              throw new \RuntimeException('Unexpected string in education quote catalog.');
            }
            $writer->writeItem($item);
          }
        }
        finally {
          $reader->close();
        }
        $report = $writer->getReport();
        $reports[$langcode] = $report;
        $lids = array_merge($lids, $report['strings']);
      }
      if ($reports) {
        _locale_refresh_translations(array_keys($reports), array_values(array_unique($lids)));
        $this->translation->reset();
      }
    }
    finally {
      $this->lock->release('labdoo_quotes.import');
    }
    return $reports;
  }

}
