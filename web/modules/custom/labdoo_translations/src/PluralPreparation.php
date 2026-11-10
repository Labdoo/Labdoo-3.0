<?php

namespace Drupal\labdoo_translations;

use Drupal\Component\Gettext\PoHeader;

/** Explicit operational setup; normal translation lookups never write state. */
final class PluralPreparation {
  public const STATE_KEY = 'locale.translation.formulae';
  public const BACKUP_PREFIX = 'labdoo_translations.plural_backup.';

  public function __construct(private ?string $directory = NULL) {
    $this->directory ??= dirname(__DIR__) . '/catalog';
  }

  /** Validate every stored formula against Drupal's non-evaluating PO parser. */
  public function canonical(): array {
    $data = json_decode(file_get_contents($this->directory . '/source.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $languages = $data['languages'] ?? [];
    $metadata = $data['plurals'] ?? [];
    if (!is_array($languages) || !is_array($metadata) || count($languages) !== 20 || count(array_unique($languages)) !== 20 || count($metadata) !== 20 || array_diff($languages, array_keys($metadata))) {
      throw new \RuntimeException('Complete canonical plural metadata for the twenty catalog languages is required.');
    }
    $parser = new PoHeader(); $result = [];
    foreach ($metadata as $language => $entry) {
      if (!is_array($entry) || !is_string($entry['header'] ?? NULL) || !is_string($entry['provenance'] ?? NULL)) {
        throw new \RuntimeException('Invalid canonical plural provenance.');
      }
      $parsed = $parser->parsePluralForms($entry['header']);
      if (!$parsed || (int) $parsed[0] < 1 || (int) $parsed[0] > 6 || (int) $parsed[0] !== ($entry['nplurals'] ?? NULL) || $parsed[1] !== ($entry['formula'] ?? NULL)) {
        throw new \RuntimeException('Canonical plural metadata differs from its PO header: ' . $language);
      }
      $result[$language] = ['plurals' => (int) $parsed[0], 'formula' => $parsed[1]];
    }
    return $result;
  }

  /** Dry run by default; apply stores an exact prior snapshot in the same DB. */
  public function prepare(bool $apply = FALSE): array {
    $canonical = $this->canonical(); $languages = \Drupal::languageManager()->getLanguages();
    $store = \Drupal::keyValue('state'); $sentinel = new \stdClass();
    $lock = \Drupal::lock(); $transaction = NULL;
    if ($apply && !$lock->acquire('labdoo_translations.plurals', 300)) {
      throw new \RuntimeException('Another plural preparation is active.');
    }
    try {
      if ($apply) {
        $transaction = \Drupal::database()->startTransaction();
        // Serialize against a simultaneous locale PO import, using the actual
        // state row before reading the snapshot (not the State service cache).
        \Drupal::database()->select('key_value', 's')->fields('s', ['name'])->condition('collection', 'state')->condition('name', self::STATE_KEY)->forUpdate()->execute()->fetchField();
      }
      $prior = $store->get(self::STATE_KEY, $sentinel);
      if ($prior !== $sentinel && !is_array($prior)) {
        throw new \RuntimeException('Existing locale plural state has an unexpected type.');
      }
      $next = $prior === $sentinel ? [] : $prior; $changed = [];
      foreach ($canonical as $language => $formula) {
        if (isset($languages[$language]) && (!isset($next[$language]) || $next[$language] != $formula)) {
          $next[$language] = $formula; $changed[] = $language;
        }
      }
      $report = ['apply' => $apply, 'changed_languages' => $changed, 'canonical_languages' => count($canonical), 'backup_key' => NULL];
      if ($apply && $changed) {
        $snapshot = ['had_value' => $prior !== $sentinel, 'formulae' => $prior === $sentinel ? NULL : $prior];
        $key = self::BACKUP_PREFIX . hash('sha256', serialize($snapshot));
        if (!$store->has($key)) {
          $store->set($key, $snapshot + ['canonical_sha256' => hash('sha256', serialize($canonical)), 'created' => time()]);
        }
        // Backup and formulas commit atomically; no node/config/locale rows.
        $store->set(self::STATE_KEY, $next);
        $report['backup_key'] = $key;
      }
      unset($transaction);
      if ($apply && $changed) {
        \Drupal::state()->resetCache();
        if (\Drupal::hasService('locale.plural.formula')) { \Drupal::service('locale.plural.formula')->reset(); }
        drupal_static_reset('locale_get_plural:plurals');
      }
      return $report;
    }
    catch (\Throwable $e) { if ($transaction) { $transaction->rollBack(); } throw $e; }
    finally { if ($apply) { $lock->release('labdoo_translations.plurals'); } }
  }
}
