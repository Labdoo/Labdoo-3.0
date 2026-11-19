<?php

namespace Drupal\labdoo_common\Commands;

use Drupal\Component\Gettext\PoHeader;
use Drupal\Component\Gettext\PoItem;
use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Component\Gettext\PoStreamWriter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\locale\PoDatabaseReader;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;

/**
 * Drush commands for maintaining version-controlled Labdoo translations.
 */
class TranslationCommands extends DrushCommands {

  /**
   * Constructs the translation commands.
   */
  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct();
  }

  /**
   * Exports untranslated strings for enabled languages into versioned PO files.
   *
   * Existing PO entries are retained so translations already committed are not
   * lost when new source strings are added.
   *
   * @command labdoo:translations-update
   * @aliases l-tu
   * @option langcodes
   *   Optional comma-separated language codes. Defaults to all configurable
   *   languages except the site's default language.
   * @option dry-run
   *   Report pending strings without writing PO files.
   * @option output-dir
   *   Output directory. Defaults to the repository's translations/custom folder.
   * @usage drush labdoo:translations-update --dry-run
   *   Show how many untranslated strings would be added per language.
   * @usage drush labdoo:translations-update
   *   Add untranslated strings to translations/custom/labdoo_custom.{langcode}.po.
   */
  public function update(array $options = ['langcodes' => '', 'dry-run' => FALSE, 'output-dir' => '']): void {
    if (!$this->moduleHandler->moduleExists('locale')) {
      throw new CommandFailedException('The Locale module must be enabled to export translations.');
    }

    $defaultLanguage = (string) $this->configFactory->get('system.site')->get('default_langcode');
    $languages = $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE);
    $requested = array_filter(array_map('trim', explode(',', (string) ($options['langcodes'] ?? ''))));
    if ($requested) {
      $unknown = array_diff($requested, array_keys($languages));
      if ($unknown) {
        throw new CommandFailedException(sprintf('Unknown or non-configurable language code(s): %s.', implode(', ', $unknown)));
      }
      $languages = array_intersect_key($languages, array_flip($requested));
    }

    $outputDirectory = rtrim((string) ($options['output-dir'] ?? ''), DIRECTORY_SEPARATOR);
    if ($outputDirectory === '') {
      $outputDirectory = dirname(DRUPAL_ROOT) . '/translations/custom';
    }
    if (empty($options['dry-run']) && !is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, TRUE) && !is_dir($outputDirectory)) {
      throw new CommandFailedException(sprintf('Could not create translation directory: %s.', $outputDirectory));
    }

    $this->io()->title('Untranslated interface strings');
    foreach ($languages as $language) {
      if ($language->getId() === $defaultLanguage || $language->isLocked()) {
        continue;
      }

      $reader = new PoDatabaseReader();
      $reader->setLangcode($language->getId());
      $reader->setOptions(['not_translated' => TRUE]);
      $pending = [];
      while ($item = $reader->readItem()) {
        if (!$this->hasEmptySource($item)) {
          $key = $this->itemKey($item);
          if (!isset($pending[$key]) || ($item->isPlural() && !$pending[$key]->isPlural())) {
            $pending[$key] = $item;
          }
        }
      }
      if (!$pending) {
        $this->io()->text(sprintf('%s: no untranslated strings', $language->getId()));
        continue;
      }

      $file = $outputDirectory . '/labdoo_custom.' . $language->getId() . '.po';
      [$header, $existing] = $this->readExistingFile($file, $language->getId(), $language->getName());
      $known = [];
      foreach ($existing as $item) {
        $known[$this->itemKey($item)] = TRUE;
      }
      $additions = [];
      foreach ($pending as $key => $item) {
        if (!isset($known[$key])) {
          $additions[] = $item;
          $known[$key] = TRUE;
        }
      }
      $this->io()->text(sprintf('%s: %d untranslated, %d new', $language->getId(), count($pending), count($additions)));

      if ($additions && empty($options['dry-run'])) {
        $this->writeMergedFile($file, $header, array_merge($existing, $additions));
      }
    }
  }

  /**
   * Reads an existing PO file, retaining its header and all entries.
   *
   * @return array{0: \Drupal\Component\Gettext\PoHeader, 1: \Drupal\Component\Gettext\PoItem[]}
   *   The file header and parsed entries.
   */
  private function readExistingFile(string $file, string $langcode, string $languageName): array {
    if (!is_file($file)) {
      $header = new PoHeader($langcode);
      $header->setProjectName((string) $this->configFactory->get('system.site')->get('name'));
      $header->setLanguageName($languageName);
      return [$header, []];
    }

    $reader = new PoStreamReader();
    $reader->setURI($file);
    $reader->open();
    $header = $reader->getHeader();
    $items = [];
    try {
      while ($item = $reader->readItem()) {
        $items[] = $item;
      }
    }
    finally {
      $reader->close();
    }
    $uniqueItems = [];
    $known = [];
    foreach ($items as $item) {
      $key = $this->itemKey($item);
      if ($this->hasEmptySource($item)) {
        continue;
      }
      if (!isset($known[$key])) {
        $uniqueItems[] = $item;
        $known[$key] = count($uniqueItems) - 1;
      }
      elseif ($item->isPlural() && !$uniqueItems[$known[$key]]->isPlural()) {
        $uniqueItems[$known[$key]] = $item;
      }
    }
    return [$header, $uniqueItems];
  }

  /**
   * Writes a merged PO file and atomically replaces the previous version.
   *
   * @param \Drupal\Component\Gettext\PoItem[] $items
   *   Entries to write.
   */
  private function writeMergedFile(string $file, PoHeader $header, array $items): void {
    $temporary = tempnam(dirname($file), '.labdoo-translations-');
    if ($temporary === FALSE) {
      throw new CommandFailedException(sprintf('Could not create a temporary file in %s.', dirname($file)));
    }

    try {
      $writer = new PoStreamWriter();
      $writer->setURI($temporary);
      $writer->setHeader($header);
      $writer->open();
      try {
        foreach ($items as $item) {
          $writer->writeItem($item);
        }
      }
      finally {
        $writer->close();
      }
      if (!rename($temporary, $file)) {
        throw new CommandFailedException(sprintf('Could not replace translation file: %s.', $file));
      }
    }
    finally {
      if (is_file($temporary)) {
        unlink($temporary);
      }
    }
  }

  /**
   * Builds a stable key for a gettext entry, including context and plurals.
   */
  private function itemKey(PoItem $item): string {
    $source = $item->getSource();
    if (is_array($source)) {
      $source = reset($source);
    }
    return hash('sha256', serialize([$item->getContext(), $source]));
  }

  /**
   * Checks for a source entry that gettext reserves for its file header.
   */
  private function hasEmptySource(PoItem $item): bool {
    $source = $item->getSource();
    return $source === '' || $source === [''];
  }

}
