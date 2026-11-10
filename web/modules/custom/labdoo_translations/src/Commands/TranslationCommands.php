<?php

namespace Drupal\labdoo_translations\Commands;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\labdoo_translations\Catalog;
use Drupal\labdoo_translations\ConfigOverride;
use Drush\Commands\DrushCommands;

/** Audits coverage using raw configuration and definitions, never saves entities. */
final class TranslationCommands extends DrushCommands {
  public function __construct(private Catalog $catalog, private ConfigOverride $override, private StorageInterface $storage, private LanguageManagerInterface $languages, private MenuLinkManagerInterface $menus) { parent::__construct(); }

  /**
   * Prepare canonical locale plural formulas with an exact prior state backup.
   *
   * @command labdoo-translations:prepare
   * @option apply Apply the plan; omitted means read-only preview.
   */
  public function prepare(array $options = ['apply' => FALSE]): int {
    $report = (new \Drupal\labdoo_translations\PluralPreparation())->prepare((bool) ($options['apply'] ?? FALSE));
    $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return 0;
  }

  /**
   * Check portable catalog and effective configuration/menu coverage safely.
   *
   * @command labdoo-translations:check
   * @option langcode A configured language code or all.
   * @option details Include missing IDs and configuration paths; never source values.
   */
  public function check(array $options = ['langcode' => 'all', 'details' => FALSE]): int {
    $selected = $options['langcode'] ?? 'all';
    $codes = $selected === 'all' ? array_keys($this->languages->getLanguages()) : [$selected];
    $configured = $this->languages->getLanguages();
    foreach ($codes as $code) { if (!isset($configured[$code])) { throw new \InvalidArgumentException('Unknown configured language.'); } }
    $names = $this->storage->listAll(); $inventory = []; $schemaErrors = [];
    foreach ($this->storage->readMultiple($names) as $name => $data) {
      try { $leaves = $this->override->leaves($name, $data); }
      catch (\Exception $e) { $schemaErrors[$name] = get_class($e); continue; }
      foreach ($leaves as $leaf) { $inventory[$name][] = $leaf + ['id' => Catalog::id($leaf['source'], $leaf['context'])]; }
    }
    $menuStrings = [];
    foreach ($this->menus->getDefinitions() as $definition) {
      foreach (['title', 'description'] as $property) {
        $value = $definition[$property] ?? NULL; $context = '';
        if ($value instanceof TranslatableMarkup) { $context = $value->getOption('context') ?? ''; $value = $value->getUntranslatedString(); }
        if (is_string($value) && trim($value) !== '') { $menuStrings[Catalog::id($value, $context)] = ['source' => $value, 'context' => $context]; }
      }
    }
    $report = ['read_only' => TRUE, 'configuration_count' => count($names), 'translatable_configurations' => count($inventory), 'menu_source_count' => count($menuStrings), 'schema_error_count' => count($schemaErrors), 'languages' => []];
    $failed = (bool) $schemaErrors;
    foreach ($codes as $code) {
      $native = $this->storage->createCollection('language.' . $code)->readMultiple(array_keys($inventory));
      $row = ['catalog' => $this->catalog->coverage($code), 'configuration_leaves' => 0, 'native_configuration_leaves' => 0, 'catalog_configuration_leaves' => 0, 'missing_configuration_leaves' => 0, 'missing_menu_ids' => []];
      $missing = [];
      foreach ($inventory as $name => $leaves) {
        $data = $native[$name] ?? [];
        foreach ($leaves as $leaf) {
          $row['configuration_leaves']++; $exists = FALSE;
          $text = NestedArray::getValue($data, $leaf['path'], $exists);
          if ($exists && is_string($text) && $text !== $leaf['source'] && Catalog::validTranslation($leaf['source'], $text)) { $row['native_configuration_leaves']++; }
          elseif ($this->catalog->lookup($code, $leaf['source'], $leaf['context']) !== NULL) { $row['catalog_configuration_leaves']++; }
          else { $row['missing_configuration_leaves']++; if ($options['details'] ?? FALSE) { $missing[] = ['id' => $leaf['id'], 'config' => $name, 'path' => $leaf['path']]; } }
        }
      }
      foreach ($menuStrings as $id => $entry) { if ($this->catalog->lookup($code, $entry['source'], $entry['context']) === NULL) { $row['missing_menu_ids'][] = $id; } }
      $row['missing_menu_count'] = count($row['missing_menu_ids']);
      if (!($options['details'] ?? FALSE)) { unset($row['catalog']['missing_ids'], $row['missing_menu_ids']); }
      else { $row['missing_configuration'] = $missing; }
      $failed = $failed || !$row['catalog']['catalog_available'] || $row['catalog']['source_count'] === 0 || $row['catalog']['translated_count'] !== $row['catalog']['source_count'] || $row['missing_configuration_leaves'] > 0 || $row['missing_menu_count'] > 0;
      $report['languages'][$code] = $row;
    }
    $report['catalog_errors'] = $this->catalog->errors();
    if ($options['details'] ?? FALSE) { $report['schema_errors'] = $schemaErrors; }
    $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return $failed || $report['catalog_errors'] ? 1 : 0;
  }
}
