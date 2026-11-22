<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Service;

use Drupal\Component\Gettext\PoHeader;
use Drupal\Component\Gettext\PoItem;
use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Component\Gettext\PoStreamWriter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\locale\StringStorageInterface;
use Drupal\labdoo_translations\Catalog;

/**
 * Shared PO inventory, merge and translation workflow for CLI and web jobs.
 */
class TranslationWorkflow {

  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected LocalTranslationAgent $translationAgent = new LocalTranslationAgent(),
    protected ?Catalog $catalog = NULL,
    protected ?StringStorageInterface $stringStorage = NULL,
  ) {
  }

  /**
   * Returns configurable target languages, excluding the source language.
   *
   * @param array $requested
   *   The requested.
   */
  public function languages(array $requested = []): array {
    if (!$this->moduleHandler->moduleExists('locale')) {
      throw new \RuntimeException('The Locale module must be enabled.');
    }
    $languages = $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE);
    if (array_diff($requested, array_keys($languages))) {
      throw new \InvalidArgumentException('Unknown or non-configurable language code.');
    }
    $default = $this->configFactory->get('system.site')->get('default_langcode');
    return array_filter($languages, static fn($language): bool => !$language->isLocked() && $language->getId() !== $default && (!$requested || in_array($language->getId(), $requested, TRUE)));
  }

  /**
   * Returns missing native entries not already covered by a reviewed catalog.
   *
   * @param string $language
   *   The language.
   */
  public function pending(string $language): array {
    // Preserve legacy direct constructors; normal services inject storage.
    $storage = $this->stringStorage ?? \Drupal::service('locale.storage');
    $items = [];
    // PoDatabaseReader repeatedly shifts/reindexes its complete result array.
    // Iterate the same untranslated records once, retaining its PoItem mapping.
    foreach ($storage->getTranslations(['language' => $language, 'translated' => FALSE]) as $string) {
      $item = new PoItem();
      $item->setFromArray((array) $string);
      if (!$this->hasEmptySource($item) && $this->catalogTranslation($item, $language) === NULL) {
        $key = $this->itemKey($item);
        if (!isset($items[$key]) || ($item->isPlural() && !$items[$key]->isPlural())) {
          $items[$key] = $item;
        }
      }
    }
    return $items;
  }

  /**
   * Prepares a merged snapshot without translating or changing any file.
   *
   * @param string $language
   *   The language.
   * @param string|null $file
   *   The file.
   */
  public function prepare(string $language, ?string $file = NULL): array {
    $languages = $this->languages([$language]);
    if (!isset($languages[$language])) {
      throw new \InvalidArgumentException('Select a target language.');
    }
    $file ??= dirname(DRUPAL_ROOT) . '/translations/custom/labdoo_custom.' . $language . '.po';
    [$header, $items] = $this->readFile($file, $language, $languages[$language]->getName());
    $pending = $this->pending($language);
    $known = [];
    foreach ($items as $item) {
      $known[$this->itemKey($item)] = TRUE;
    }
    $new = 0;
    foreach ($pending as $key => $item) {
      if (!isset($known[$key])) {
        $items[] = $item;
        $known[$key] = TRUE;
        $new++;
      }
    }
    return ['header' => $header, 'items' => $items, 'pending' => count($pending), 'new' => $new];
  }

  /**
   * Translates copies of entries, respecting existing and reviewed values.
   *
   * @param array $items
   *   The items.
   * @param string $language
   *   The language.
   * @param \Drupal\Component\Gettext\PoHeader $header
   *   The header.
   * @param array $options
   *   The options.
   */
  public function translate(array $items, string $language, PoHeader $header, array $options): array {
    $items = $this->translationItems($items, $language);
    $plurals = preg_match('/nplurals\s*=\s*(\d+)/', $header->getPluralForms(), $match) ? (int) $match[1] : 2;
    return $this->translationAgent->translate($items, $language, $plurals, (string) $options['agent'], (string) ($options['agent-binary'] ?? ''), (string) ($options['agent-model'] ?? ''), (int) ($options['batch-size'] ?? 20), (int) ($options['agent-timeout'] ?? 180), $header->getPluralForms());
  }

  /**
   * Resolves existing and reviewed values before costing or translating.
   *
   * @param array $items
   *   The items.
   * @param string $language
   *   The language.
   */
  public function translationItems(array $items, string $language): array {
    $items = array_map(static fn(PoItem $item): PoItem => clone $item, $items);
    foreach ($items as $item) {
      $reviewed = $this->catalogTranslation($item, $language);
      if ($reviewed !== NULL) {
        $forms = (array) $item->getTranslation();
        foreach (explode(PoItem::DELIMITER, $reviewed) as $index => $value) {
          if (($forms[$index] ?? '') === '') {
            $forms[$index] = $value;
          }
        }
        $item->setTranslation($item->isPlural() ? $forms : $forms[0]);
      }
    }
    return $items;
  }

  /**
   * Reads one checkpointed PO batch without reparsing all earlier entries.
   *
   * @param string $file
   *   The file.
   * @param int $seek
   *   The seek.
   * @param int $count
   *   The count.
   */
  public function readBatch(string $file, int $seek, int $count): array {
    $reader = new PoStreamReader();
    $reader->setURI($file);
    $reader->open();
    $header = $reader->getHeader();
    $items = [];
    try {
      if ($seek > 0) {
        $reader->setSeek($seek);
      }
      while (count($items) < $count && ($item = $reader->readItem())) {
        $items[] = $item;
      }
      $next = $reader->getSeek();
    }
    finally {
      $reader->close();
    }
    return [$header, $items, $next];
  }

  /**
   * Executes the existing CLI export workflow with optional progress output.
   *
   * @param array $options
   *   The options.
   * @param callable|null $report
   *   The report.
   */
  public function update(array $options, ?callable $report = NULL): void {
    $agent = (string) ($options['agent'] ?? '');
    if ($agent !== '') {
      $this->translationAgent->validate($agent, (int) ($options['batch-size'] ?? 20), (int) ($options['agent-timeout'] ?? 180), (string) ($options['agent-binary'] ?? ''));
    }
    elseif (!empty($options['agent-binary']) || !empty($options['agent-model'])) {
      throw new \InvalidArgumentException('Select --agent when specifying its binary or model.');
    }
    $requested = array_filter(array_map('trim', explode(',', (string) ($options['langcodes'] ?? ''))));
    $languages = $this->languages(array_values($requested));
    $directory = rtrim((string) ($options['output-dir'] ?? ''), DIRECTORY_SEPARATOR) ?: dirname(DRUPAL_ROOT) . '/translations/custom';
    if (empty($options['dry-run']) && !is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new \RuntimeException('Could not create translation directory.');
    }
    foreach ($languages as $language => $definition) {
      $file = $directory . '/labdoo_custom.' . $language . '.po';
      $snapshot = $this->prepare($language, $file);
      if ($report) {
        $report(sprintf('%s: %d untranslated, %d new', $language, $snapshot['pending'], $snapshot['new']));
      }
      if (!empty($options['dry-run'])) {
        continue;
      }
      $items = $snapshot['items'];
      $before = array_map(static fn(PoItem $item) => $item->getTranslation(), $items);
      if ($agent !== '') {
        $items = $this->translate($items, $language, $snapshot['header'], $options);
      }
      $after = array_map(static fn(PoItem $item) => $item->getTranslation(), $items);
      if ($items && ($snapshot['new'] || $before !== $after)) {
        $this->writeFile($file, $snapshot['header'], $items);
      }
    }
  }

  /**
   * Looks up reviewed portable translations without modifying runtime storage.
   *
   * @param \Drupal\Component\Gettext\PoItem $item
   *   The item.
   * @param string $language
   *   The language.
   */
  private function catalogTranslation(PoItem $item, string $language): ?string {
    $source = $item->getSource();
    $source = is_array($source) ? implode(PoItem::DELIMITER, $source) : $source;
    return $this->catalog?->lookup($language, $source, (string) $item->getContext());
  }

  /**
   * Reads an existing PO file, retaining its header and all entries.
   *
   * @param string $file
   *   The file.
   * @param string $langcode
   *   The langcode.
   * @param string $languageName
   *   The language name.
   *
   * @return array{0: \Drupal\Component\Gettext\PoHeader, 1: \Drupal\Component\Gettext\PoItem[]}
   *   The file header and parsed entries.
   */
  public function readFile(string $file, string $langcode, string $languageName): array {
    if (!is_file($file)) {
      $header = new PoHeader($langcode);
      $header->setProjectName((string) $this->configFactory->get('system.site')->get('name'));
      $header->setLanguageName($languageName);
      // The bundled metadata is useful even when the runtime catalog is
      // disabled.
      $path = DRUPAL_ROOT . '/' . dirname($this->moduleHandler->getModule('labdoo_common')->getPath()) . '/labdoo_translations/catalog/source.json';
      if (is_file($path)) {
        $metadata = json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
        if (isset($metadata['plurals'][$langcode]['header'])) {
          $header->setFromString('Plural-Forms: ' . $metadata['plurals'][$langcode]['header'] . "\n");
        }
      }
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
   * @param string $file
   *   The file.
   * @param \Drupal\Component\Gettext\PoHeader $header
   *   The header.
   * @param \Drupal\Component\Gettext\PoItem[] $items
   *   Entries to write.
   */
  public function writeFile(string $file, PoHeader $header, array $items): void {
    $temporary = tempnam(dirname($file), '.labdoo-translations-');
    if ($temporary === FALSE) {
      throw new \RuntimeException(sprintf('Could not create a temporary file in %s.', dirname($file)));
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
        throw new \RuntimeException(sprintf('Could not replace translation file: %s.', $file));
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
   *
   * @param \Drupal\Component\Gettext\PoItem $item
   *   The item.
   */
  public function itemKey(PoItem $item): string {
    $source = $item->getSource();
    if (is_array($source)) {
      $source = reset($source);
    }
    return hash('sha256', serialize([$item->getContext(), $source]));
  }

  /**
   * Checks for a source entry that gettext reserves for its file header.
   *
   * @param \Drupal\Component\Gettext\PoItem $item
   *   The item.
   */
  private function hasEmptySource(PoItem $item): bool {
    $source = $item->getSource();
    return $source === '' || $source === [''];
  }

}
