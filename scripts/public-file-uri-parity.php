<?php

/**
 * @file
 * Repairs public managed-file URIs using verified, already copied D7 originals.
 *
 * Dry-run by default. Run --help for options. No content entity is saved.
 */

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;

final class LabdooPublicFileUriParity {
  private array $sourceById = [];
  private array $sourceByName = [];
  private array $sourceByUuid = [];
  private array $mapByDestination = [];
  private array $targetOwners = [];
  private int $hashSamples = 0;

  public function __construct(
    private Connection $database,
    private ?Connection $source,
    private string $publicRoot,
    private ?string $sourceRoot = NULL,
    private ?array $sourceFixture = NULL,
  ) {
    $this->publicRoot = realpath($publicRoot) ?: throw new RuntimeException('Public files directory is unavailable.');
    if ($sourceFixture !== NULL && (!\Drupal\Core\Site\Settings::get('labdoo_e2e') || !str_starts_with($database->getConnectionOptions()['database'], 'labdoo_e2e_'))) {
      throw new RuntimeException('Source fixtures are restricted to isolated tests.');
    }
  }

  private static function relativePath(string $uri): ?string {
    if (!str_starts_with($uri, 'public://')) {
      return NULL;
    }
    $relative = substr($uri, 9);
    $parts = [];
    foreach (explode('/', $relative) as $part) {
      if ($part === '..' || str_contains($part, "\0") || str_contains($part, '\\')) {
        return NULL;
      }
      if ($part !== '' && $part !== '.') {
        $parts[] = $part;
      }
    }
    if (!$parts || in_array(strtolower($parts[0]), ['styles', 'css', 'js', 'php', 'private'], TRUE)) {
      return NULL;
    }
    $path = implode('/', $parts);
    if (preg_match('/\.(php\d*|phtml|phar|htaccess)$/i', $path)) {
      return NULL;
    }
    return $path;
  }

  private function loadSources(): void {
    foreach ($this->database->query('SELECT fid, uri FROM {file_managed}')->fetchAll() as $file) {
      $this->targetOwners[$file->uri][] = (int) $file->fid;
    }
    $rows = $this->sourceFixture ?? $this->source?->query('SELECT fid, uuid, filename, uri, filemime, filesize FROM {file_managed}')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === NULL) {
      throw new RuntimeException('A read-only external source connection is required.');
    }
    foreach ($rows as $row) {
      $relative = self::relativePath((string) $row['uri']);
      if ($relative === NULL) {
        continue;
      }
      $row['relative'] = $relative;
      $this->sourceById[(int) $row['fid']] = $row;
      $this->sourceByName[(string) $row['filename']][$relative] = $row;
      if (!empty($row['uuid'])) {
        $this->sourceByUuid[$row['uuid']][$relative] = $row;
      }
    }
    if ($this->sourceFixture === NULL && $this->database->schema()->tableExists('migrate_map_d7_file')) {
      foreach ($this->database->query('SELECT sourceid1, destid1 FROM {migrate_map_d7_file} WHERE destid1 IS NOT NULL')->fetchAll() as $row) {
        if (isset($this->sourceById[(int) $row->sourceid1])) {
          $this->mapByDestination[(int) $row->destid1][$this->sourceById[(int) $row->sourceid1]['relative']] = $this->sourceById[(int) $row->sourceid1];
        }
      }
    }
  }

  private static function mime(string $mime): string {
    return match (strtolower($mime)) {
      'image/jpg', 'image/pjpeg' => 'image/jpeg',
      'application/x-pdf' => 'application/pdf',
      default => strtolower($mime),
    };
  }

  private function candidate(array $file): array {
    $mapped = $this->mapByDestination[(int) $file['fid']] ?? [];
    $uuid = $this->sourceByUuid[$file['uuid']] ?? [];
    if ($mapped && $uuid && array_keys($mapped) !== array_keys($uuid)) {
      return ['skip' => 'identity_conflict'];
    }
    $proof = $mapped ? 'migrate_map_d7_file' : ($uuid ? 'd7_uuid' : 'unique_d7_filename');
    $matches = $mapped ?: $uuid ?: ($this->sourceByName[$file['filename']] ?? []);
    if (count($matches) !== 1) {
      return ['skip' => $matches ? 'ambiguous_source' : 'no_source_identity'];
    }
    $source = reset($matches);
    if ($proof === 'unique_d7_filename' && basename($file['uri']) !== $source['filename']) {
      return ['skip' => 'filename_uri_conflict'];
    }
    $target = realpath($this->publicRoot . '/' . $source['relative']);
    if (!$target || !str_starts_with($target, $this->publicRoot . '/') || !is_file($target)) {
      return ['skip' => 'source_original_not_copied'];
    }
    if (self::relativePath('public://' . substr($target, strlen($this->publicRoot) + 1)) === NULL) {
      return ['skip' => 'excluded_physical_target'];
    }
    $bytes = filesize($target);
    if ($source['filesize'] === NULL || $bytes !== (int) $source['filesize']) {
      return ['skip' => 'd7_size_conflict'];
    }
    $mime = self::mime((new finfo(FILEINFO_MIME_TYPE))->file($target) ?: '');
    if (!$mime || $mime !== self::mime((string) $source['filemime']) || str_contains($mime, 'php')) {
      return ['skip' => 'd7_mime_conflict'];
    }
    $placeholder = (int) ($file['filesize'] ?? -1) === 68 && str_contains($file['uri'], '/content/image/migrate/');
    if ($file['filesize'] !== NULL && (int) $file['filesize'] !== $bytes && !$placeholder) {
      return ['skip' => 'v3_size_conflict'];
    }
    $after = ['uri' => 'public://' . $source['relative']];
    if (array_diff($this->targetOwners[$after['uri']] ?? [], [(int) $file['fid']])) {
      return ['skip' => 'uri_already_managed'];
    }
    if ($placeholder || $file['filesize'] === NULL) {
      $after['filesize'] = $bytes;
      $after['filemime'] = $mime;
    }
    if ($file['filename'] !== $source['filename']) {
      if ($proof === 'unique_d7_filename') {
        return ['skip' => 'filename_conflict'];
      }
      $after['filename'] = $source['filename'];
    }
    if (!array_diff_assoc($after, $file)) {
      return ['skip' => 'already_consistent'];
    }
    $proofData = [
      'identity' => $proof,
      'source_fid' => (int) $source['fid'],
      'source_uri' => $source['uri'],
      'source_filename' => $source['filename'],
      'physical_path' => $target,
      'actual_bytes' => $bytes,
      'physical_mtime' => filemtime($target),
      'actual_mime' => $mime,
      'placeholder_metadata' => $placeholder,
    ];
    // A small bounded checksum sample supplements identity; no mass hashing.
    if ($this->hashSamples < 8 && $bytes <= 2097152 && $this->sourceRoot) {
      $original = realpath($this->sourceRoot . '/' . $source['relative']);
      $sourceRoot = realpath($this->sourceRoot);
      if ($original && $sourceRoot && str_starts_with($original, $sourceRoot . '/') && is_file($original)) {
        $hash = hash_file('sha256', $target);
        if ($hash !== hash_file('sha256', $original)) {
          return ['skip' => 'source_checksum_conflict'];
        }
        $proofData['sample_sha256'] = $hash;
        $this->hashSamples++;
      }
    }
    $dimensions = str_starts_with($mime, 'image/') ? @getimagesize($target) : FALSE;
    return ['file' => $file, 'after' => $after, 'proof' => $proofData, 'dimensions' => $dimensions ? [(int) $dimensions[0], (int) $dimensions[1]] : NULL, 'updates' => []];
  }

  /**
   * Returns a dry-run plan. No state, entities or identifiers are written.
   */
  public function plan(array $fids = [], int $limit = 0): array {
    $sourcesLoaded = FALSE;
    $this->hashSamples = 0;
    $counts = ['scanned' => 0, 'already_present' => 0, 'repairable' => 0];
    $records = [];
    $last = 0;
    do {
      $query = $this->database->select('file_managed', 'f')->fields('f')->condition('fid', $last, '>')->orderBy('fid')->range(0, 500);
      if ($fids) {
        $query->condition('fid', $fids, 'IN');
      }
      $rows = $query->execute()->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as $file) {
        $last = (int) $file['fid'];
        $counts['scanned']++;
        if (!str_starts_with($file['uri'], 'public://') && !str_starts_with($file['uri'], 'sites/default/files/') && !str_starts_with($file['uri'], '/sites/default/files/')) {
          $counts['non_public_or_unsupported_uri'] = ($counts['non_public_or_unsupported_uri'] ?? 0) + 1;
          continue;
        }
        $relative = self::relativePath((string) $file['uri']);
        if (str_starts_with($file['uri'], 'public://') && $relative === NULL) {
          $counts['unsafe_or_excluded_uri'] = ($counts['unsafe_or_excluded_uri'] ?? 0) + 1;
          continue;
        }
        $existing = $relative !== NULL ? realpath($this->publicRoot . '/' . $relative) : FALSE;
        $placeholder = (int) ($file['filesize'] ?? -1) === 68 && str_contains($file['uri'], '/content/image/migrate/');
        if ($existing && is_file($existing)) {
          $isTransparentPlaceholder = $placeholder && filesize($existing) === 68 && hash_file('sha256', $existing) === hash('sha256', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
          if (!$isTransparentPlaceholder) {
            $counts['already_present']++;
            continue;
          }
        }
        if (!$sourcesLoaded) {
          $this->loadSources();
          $sourcesLoaded = TRUE;
        }
        $record = $this->candidate($file);
        if (isset($record['skip'])) {
          $counts[$record['skip']] = ($counts[$record['skip']] ?? 0) + 1;
          continue;
        }
        $records[] = $record;
        $counts['repairable']++;
        if ($limit && count($records) >= $limit) {
          break 2;
        }
      }
    } while (count($rows) === 500);
    $targets = array_count_values(array_column(array_column($records, 'after'), 'uri'));
    $records = array_values(array_filter($records, static fn(array $record): bool => $targets[$record['after']['uri']] === 1));
    $collisions = $counts['repairable'] - count($records);
    if ($collisions) {
      $counts['planned_uri_collision'] = $collisions;
      $counts['repairable'] = count($records);
    }
    $this->attachReferenceUpdates($records);
    $counts['dimension_rows'] = array_sum(array_map(static fn(array $record): int => count($record['updates']), $records));
    $counts['checksum_samples'] = $this->hashSamples;
    return ['schema' => 1, 'captured_at' => gmdate('c'), 'database' => $this->database->getConnectionOptions()['database'], 'summary' => $counts, 'records' => $records];
  }

  private static function identifier(string $value): string {
    if (!preg_match('/^[a-z0-9_]+$/i', $value)) {
      throw new RuntimeException('Unsafe SQL identifier.');
    }
    return $value;
  }

  private function attachReferenceUpdates(array &$records): void {
    if (!$records) {
      return;
    }
    $positions = [];
    foreach ($records as $position => &$record) {
      $positions[(int) $record['file']['fid']] = $position;
      $record['tags'] = ['file:' . $record['file']['fid']];
    }
    unset($record);
    $manager = \Drupal::entityTypeManager();
    foreach ($manager->getDefinitions() as $entityType => $entityDefinition) {
      if (!is_a($entityDefinition->getClass(), \Drupal\Core\Entity\FieldableEntityInterface::class, TRUE)) {
        continue;
      }
      foreach (\Drupal::service('entity_field.manager')->getFieldStorageDefinitions($entityType) as $field => $definition) {
        if (!in_array($definition->getType(), ['file', 'image'], TRUE) && !($definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'file')) {
          continue;
        }
        $storage = $manager->getStorage($entityType);
        if (!method_exists($storage, 'getTableMapping')) {
          throw new RuntimeException('A referenced file field has no SQL mapping.');
        }
        $mapping = $storage->getTableMapping();
        $current = $mapping->getFieldTableName($field);
        $tables = [$current];
        $revisionKey = $entityDefinition->getKey('revision');
        if ($definition->isRevisionable() && $revisionKey) {
          $revisionTable = $mapping->requiresDedicatedTableStorage($definition) ? $mapping->getDedicatedRevisionTableName($definition) : ($entityDefinition->getRevisionDataTable() ?: $entityDefinition->getRevisionTable());
          if ($revisionTable && $revisionTable !== $current) {
            $tables[] = $revisionTable;
          }
        }
        $target = self::identifier($mapping->getFieldColumnName($definition, 'target_id'));
        $width = $definition->getType() === 'image' ? self::identifier($mapping->getFieldColumnName($definition, 'width')) : NULL;
        $height = $definition->getType() === 'image' ? self::identifier($mapping->getFieldColumnName($definition, 'height')) : NULL;
        $base = self::identifier($entityDefinition->getBaseTable());
        $id = self::identifier($entityDefinition->getKey('id'));
        foreach (array_unique($tables) as $table) {
          $table = self::identifier($table);
          if (!$this->database->schema()->tableExists($table)) {
            continue;
          }
          $entityColumn = $this->database->schema()->fieldExists($table, 'entity_id') ? 'entity_id' : $id;
          $keys = [$entityColumn, $target];
          foreach (['revision_id', 'langcode', 'delta', 'deleted'] as $key) {
            if ($this->database->schema()->fieldExists($table, $key)) {
              $keys[] = $key;
            }
          }
          $join = 'e.' . $id . '=t.' . $entityColumn;
          if ($revisionKey && $this->database->schema()->fieldExists($table, 'revision_id')) {
            $join .= ' AND e.' . self::identifier($revisionKey) . '=t.revision_id';
          }
          elseif ($revisionKey && $this->database->schema()->fieldExists($table, $revisionKey)) {
            $keys[] = $revisionKey;
            $join .= ' AND e.' . self::identifier($revisionKey) . '=t.' . self::identifier($revisionKey);
          }
          $columns = array_unique(array_merge($keys, array_filter([$width, $height])));
          $select = implode(', ', array_map(static fn(string $column): string => 't.' . $column, $columns));
          $query = 'SELECT ' . $select . ' FROM {' . $table . '} t INNER JOIN {' . $base . '} e ON ' . $join . ' WHERE t.' . $target . ' IN (:fids[])';
          if (in_array('deleted', $keys, TRUE)) {
            $query .= ' AND t.deleted=0';
          }
          foreach ($this->database->query($query, [':fids[]' => array_keys($positions)])->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $record = &$records[$positions[(int) $row[$target]]];
            $record['tags'][] = $entityType . ':' . $row[$entityColumn];
            if ($width && $height && $record['dimensions'] && ((int) ($row[$width] ?? 0) <= 1 || (int) ($row[$height] ?? 0) <= 1)) {
              [$actualWidth, $actualHeight] = $record['dimensions'];
              if ((int) $row[$width] !== $actualWidth || (int) $row[$height] !== $actualHeight) {
                $where = array_intersect_key($row, array_fill_keys($keys, TRUE));
                $record['updates'][] = ['table' => $table, 'where' => $where, 'before' => [$width => $row[$width], $height => $row[$height]], 'after' => [$width => $actualWidth, $height => $actualHeight]];
              }
            }
            unset($record);
          }
        }
      }
    }
    foreach ($records as &$record) {
      $record['tags'] = array_values(array_unique($record['tags']));
    }
  }

  private function guardedQuery(string $table, array $where, array $expected, bool $lock = FALSE) {
    $query = $this->database->select(self::identifier($table), 't')->fields('t');
    foreach (array_merge($where, $expected) as $field => $value) {
      $value === NULL ? $query->isNull(self::identifier($field)) : $query->condition(self::identifier($field), $value);
    }
    if ($lock) {
      $query->forUpdate();
    }
    return $query;
  }

  private function fileMatches(array $expected): bool {
    $row = $this->guardedQuery('file_managed', ['fid' => $expected['fid']], [], TRUE)->execute()->fetchAssoc();
    if (!$row) {
      return FALSE;
    }
    foreach ($expected as $field => $value) {
      if (!array_key_exists($field, $row) || ($value === NULL ? $row[$field] !== NULL : (string) $row[$field] !== (string) $value)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  public static function privateOutput(string $path) {
    $parent = realpath(dirname($path));
    $webRoot = realpath(\Drupal::root());
    if (!$parent || ($webRoot && ($parent === $webRoot || str_starts_with($parent, $webRoot . '/')))) {
      throw new RuntimeException('Private output must be outside the web root.');
    }
    $handle = fopen($path, 'x');
    if (!$handle || !chmod($path, 0600)) {
      throw new RuntimeException('Cannot create a new private output file.');
    }
    return $handle;
  }

  private static function durableLine($handle, array $record): void {
    $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    if (fwrite($handle, $line) !== strlen($line) || !fflush($handle) || !fsync($handle)) {
      throw new RuntimeException('Cannot durably write the repair backup.');
    }
  }

  /**
   * Applies in bounded transactions, after a durable full before-image backup.
   */
  public function apply(array $plan, string $backupPath): array {
    $webRoot = realpath(\Drupal::root());
    $parent = realpath(dirname($backupPath));
    if (!$parent || ($webRoot && ($parent === $webRoot || str_starts_with($parent, $webRoot . '/')))) {
      throw new RuntimeException('Backup must be outside the web root.');
    }
    $backup = self::privateOutput($backupPath);
    $applied = 0;
    $conflicts = 0;
    try {
      self::durableLine($backup, ['kind' => 'header', 'schema' => 1, 'database' => $this->database->getConnectionOptions()['database'], 'captured_at' => gmdate('c')]);
      foreach (array_chunk($plan['records'], 100) as $batch) {
        $transaction = $this->database->startTransaction();
        $tags = [];
        try {
          foreach ($batch as $record) {
            $before = $record['file'];
            clearstatcache(TRUE, $record['proof']['physical_path']);
            if (!is_file($record['proof']['physical_path']) || filesize($record['proof']['physical_path']) !== $record['proof']['actual_bytes'] || filemtime($record['proof']['physical_path']) !== $record['proof']['physical_mtime']) {
              $conflicts++;
              continue;
            }
            if (!$this->fileMatches($before)) {
              $conflicts++;
              continue;
            }
            if ($this->database->select('file_managed', 'f')->fields('f', ['fid'])->condition('uri', $record['after']['uri'])->condition('fid', $before['fid'], '<>')->forUpdate()->execute()->fetchField()) {
              $conflicts++;
              continue;
            }
            foreach ($record['updates'] as $update) {
              if (!$this->guardedQuery($update['table'], $update['where'], $update['before'], TRUE)->execute()->fetchAssoc()) {
                throw new RuntimeException('Concurrent image metadata change; current batch rolled back.');
              }
            }
            self::durableLine($backup, ['kind' => 'file', 'record' => $record]);
            // Use guards on every update, even after locking the rows.
            foreach ($record['updates'] as $update) {
              $query = $this->database->update($update['table'])->fields($update['after']);
              foreach (array_merge($update['where'], $update['before']) as $field => $value) {
                $value === NULL ? $query->isNull($field) : $query->condition($field, $value);
              }
              if ($query->execute() !== 1) {
                throw new RuntimeException('Image metadata update did not match exactly one row.');
              }
            }
            $query = $this->database->update('file_managed')->fields($record['after'])->condition('fid', $before['fid'])->condition('uri', $before['uri']);
            if ($query->execute() !== 1) {
              throw new RuntimeException('File URI update did not match exactly one row.');
            }
            $tags = array_merge($tags, $record['tags']);
            $applied++;
          }
          unset($transaction);
        }
        catch (Throwable $e) {
          $transaction->rollBack();
          throw $e;
        }
        Cache::invalidateTags(array_unique($tags));
      }
      self::durableLine($backup, ['kind' => 'complete', 'applied' => $applied, 'conflicts' => $conflicts]);
    }
    finally {
      fclose($backup);
    }
    return ['applied' => $applied, 'concurrent_conflicts' => $conflicts];
  }

  /**
   * Restores before-images only when the after-images still match exactly.
   */
  public function rollback(string $backupPath): array {
    $records = [];
    $header = NULL;
    foreach (file($backupPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $entry = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
      if ($entry['kind'] === 'header') {
        $header = $entry;
      }
      elseif ($entry['kind'] === 'file') {
        $records[] = $entry['record'];
      }
    }
    if (!$header || $header['schema'] !== 1 || $header['database'] !== $this->database->getConnectionOptions()['database']) {
      throw new RuntimeException('Backup schema/database mismatch.');
    }
    $restored = 0;
    $conflicts = 0;
    foreach (array_chunk(array_reverse($records), 100) as $batch) {
      $transaction = $this->database->startTransaction();
      $tags = [];
      try {
        foreach ($batch as $record) {
          $expected = array_replace($record['file'], $record['after']);
          if (!$this->fileMatches($expected)) {
            $conflicts++;
            continue;
          }
          foreach ($record['updates'] as $update) {
            if (!$this->guardedQuery($update['table'], $update['where'], $update['after'], TRUE)->execute()->fetchAssoc()) {
              throw new RuntimeException('Concurrent dimensions change prevents rollback.');
            }
          }
          foreach ($record['updates'] as $update) {
            $query = $this->database->update(self::identifier($update['table']))->fields($update['before']);
            foreach (array_merge($update['where'], $update['after']) as $field => $value) {
              $value === NULL ? $query->isNull(self::identifier($field)) : $query->condition(self::identifier($field), $value);
            }
            if ($query->execute() !== 1) {
              throw new RuntimeException('Image rollback did not match exactly one row.');
            }
          }
          $before = array_intersect_key($record['file'], $record['after']);
          if ($this->database->update('file_managed')->fields($before)->condition('fid', $record['file']['fid'])->condition('uri', $record['after']['uri'])->execute() !== 1) {
            throw new RuntimeException('File rollback did not match exactly one row.');
          }
          $tags = array_merge($tags, $record['tags']);
          $restored++;
        }
        unset($transaction);
      }
      catch (Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      Cache::invalidateTags(array_unique($tags));
    }
    return ['restored' => $restored, 'concurrent_conflicts' => $conflicts];
  }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  umask(0077);
  $options = getopt('', ['root:', 'uri:', 'apply', 'backup-path:', 'manifest-path:', 'source-root:', 'limit:', 'fids:', 'rollback:', 'help']);
  if (isset($options['help'])) {
    echo "Dry-run: php scripts/public-file-uri-parity.php --root=/path/web --manifest-path=/private/new-plan.json\nApply: add --apply --backup-path=/private/new-backup.jsonl\nRollback: --rollback=/private/backup.jsonl --apply (no source connection required)\nOptions: --uri=https://v3.labdoo.org --source-root=/mnt/drupal-files-source --limit=100 --fids=123,456\n";
    exit(0);
  }
  $root = realpath($options['root'] ?? dirname(__DIR__) . '/web');
  if (!$root || !is_file($root . '/autoload.php')) {
    throw new RuntimeException('Use --root to select the installed Drupal web root.');
  }
  $loader = require $root . '/autoload.php';
  $request = \Symfony\Component\HttpFoundation\Request::create($options['uri'] ?? 'https://v3.labdoo.org');
  \Drupal\Core\DrupalKernel::createFromRequest($request, $loader, 'prod', FALSE, $root)->boot();
  $active = Database::getConnection()->getKey();
  try {
    $database = Database::getConnection('default', 'default');
    $source = isset($options['rollback']) ? NULL : Database::getConnection('default', 'external');
    $runner = new LabdooPublicFileUriParity($database, $source, \Drupal::service('file_system')->realpath('public://'), $options['source-root'] ?? '/mnt/drupal-files-source');
    if (isset($options['rollback'])) {
      if (!isset($options['apply'])) {
        throw new RuntimeException('Rollback requires explicit --apply.');
      }
      echo json_encode($runner->rollback($options['rollback']), JSON_PRETTY_PRINT) . PHP_EOL;
    }
    else {
      $fids = [];
      if (isset($options['fids'])) {
        foreach (explode(',', $options['fids']) as $fid) {
          if (!ctype_digit($fid) || (int) $fid < 1) {
            throw new RuntimeException('Every --fids value must be a positive integer.');
          }
          $fids[] = (int) $fid;
        }
      }
      if (isset($options['limit']) && (!ctype_digit($options['limit']) || (int) $options['limit'] < 1)) {
        throw new RuntimeException('--limit must be a positive integer.');
      }
      $plan = $runner->plan($fids, (int) ($options['limit'] ?? 0));
      if (isset($options['manifest-path'])) {
        $output = LabdooPublicFileUriParity::privateOutput($options['manifest-path']);
        fwrite($output, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fflush($output);
        fsync($output);
        fclose($output);
      }
      $summary = $plan['summary'];
      if (isset($options['apply'])) {
        if (empty($options['backup-path'])) {
          throw new RuntimeException('Apply requires a new private --backup-path.');
        }
        $summary += $runner->apply($plan, $options['backup-path']);
      }
      echo json_encode(['mode' => isset($options['apply']) ? 'apply' : 'dry-run', 'summary' => $summary], JSON_PRETTY_PRINT) . PHP_EOL;
    }
  }
  finally {
    Database::setActiveConnection($active);
  }
}
