<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Database\Connection;

/**
 * Bounded, SELECT-only reader of Drupal 7 CURRENT node state.
 *
 * Roots are tnid=0 or nid=tnid. Fingerprints include their entire translation
 * family, current instantiated field_data rows, current locations and referenced
 * file metadata. They deliberately exclude revisions, access/view counters,
 * derived file_usage, and binary bytes (a separate files audit is required).
 */
final class NodeSourceReader {

  private array $schema = [];
  private array $mappings = [];
  private array $typeSchemas = [];
  private ?array $cached = NULL;
  private string $cacheKey = '';
  private int $bytes = 0;
  private const MAX_IDS = 500;
  private const MAX_BYTES = 16777216;
  private const MAX_ROWS = 100000;

  public function __construct(private Connection $source, array $mappingConfig = []) {
    $options = $source->getConnectionOptions();
    $database = $options['database'] ?? '';
    if (!$database || $source->driver() !== 'mysql') {
      throw new \RuntimeException('Incremental node reader requires a MySQL/MariaDB Drupal 7 source.');
    }
    // Information schema is read through SELECT, never SHOW/DDL or active swaps.
    $rows = $source->query('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :database ORDER BY TABLE_NAME, ORDINAL_POSITION', [':database' => $database]);
    $prefix = trim($source->prefixTables('{node}'), '`"');
    if (!str_ends_with($prefix, 'node')) {
      throw new \RuntimeException('Unexpected source table prefix.');
    }
    $prefix = substr($prefix, 0, -4);
    foreach ($rows as $row) {
      if (!str_starts_with($row->TABLE_NAME, $prefix)) {
        continue;
      }
      $table = substr($row->TABLE_NAME, strlen($prefix));
      if (in_array($table, ['node', 'location_instance', 'location', 'file_managed', 'field_config_instance', 'field_collection_item'], TRUE) || str_starts_with($table, 'field_data_')) {
        $this->schema[$table][$row->COLUMN_NAME] = strtolower($row->DATA_TYPE);
      }
    }
    $this->requireColumns('node', ['nid', 'vid', 'type', 'tnid', 'language', 'changed', 'status', 'title', 'uid', 'created']);
    $this->requireIntegers('node', ['nid', 'vid', 'tnid', 'changed', 'status', 'uid', 'created']);
    $this->requireColumns('field_config_instance', ['field_name', 'entity_type', 'bundle', 'deleted']);
    if (!$mappingConfig) {
      foreach (glob(dirname(__DIR__, 3) . '/config/fields_mapping/*.json') as $path) {
        $mappingConfig[basename($path, '.json')] = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
      }
    }
    if (isset($mappingConfig['source_type'])) {
      $mappingConfig = [$mappingConfig];
    }
    foreach ($mappingConfig as $key => $config) {
      if (is_array($config) && ($config['entity_type'] ?? 'node') === 'node' && isset($config['source_type'])) {
        $this->mappings[$config['source_type']] = $config;
      }
    }
    if (!$this->mappings) {
      throw new \RuntimeException('Explicit node mappings are required for incremental coverage.');
    }
  }

  private function requireColumns(string $table, array $columns): void {
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $table) || !isset($this->schema[$table])) {
      throw new \RuntimeException('Missing or unsupported source table: ' . $table);
    }
    foreach ($columns as $column) {
      if (!isset($this->schema[$table][$column])) {
        throw new \RuntimeException("Missing source column $table.$column");
      }
    }
  }

  private function ids(array $ids): array {
    if (count($ids) > self::MAX_IDS) {
      throw new \InvalidArgumentException('Source batches are limited to 500 IDs.');
    }
    foreach ($ids as $id) {
      if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1) {
        throw new \InvalidArgumentException('Source IDs must be positive integers.');
      }
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  private function requireIntegers(string $table, array $columns): void {
    foreach ($columns as $column) {
      if (!in_array($this->schema[$table][$column] ?? '', ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], TRUE)) {
        throw new \RuntimeException("Unexpected source key type: $table.$column");
      }
    }
  }

  private function coverage(string $type): array {
    if (!isset($this->mappings[$type])) {
      throw new \RuntimeException('Unmapped source node type: ' . $type);
    }
    if (isset($this->typeSchemas[$type])) {
      return $this->typeSchemas[$type];
    }
    $tables = [];
    $instances = [];
    $query = $this->source->select('field_config_instance', 'i')->fields('i')->condition('entity_type', 'node')->condition('bundle', $type)->condition('deleted', 0);
    foreach ($query->execute() as $instance) {
      $row = (array) $instance;
      $table = 'field_data_' . $row['field_name'];
      $this->requireColumns($table, ['entity_type', 'entity_id', 'bundle', 'deleted', 'revision_id', 'language', 'delta']);
      $this->requireIntegers($table, ['entity_id', 'deleted', 'revision_id', 'delta']);
      $tables[$table] = $this->schema[$table];
      $instances[] = $row;
    }
    $locations = FALSE;
    foreach ($this->mappings[$type]['fields_mapping'] ?? [] as $mapping) {
      foreach ($mapping['source']['fields'] ?? [] as $field) {
        $table = $field['table'] ?? '';
        if ($table === 'node') {
          $this->requireColumns('node', isset($field['expression']) ? [] : [$field['field']]);
        }
        elseif (str_starts_with($table, 'field_data_')) {
          $this->requireColumns($table, ['entity_type', 'entity_id', 'bundle', 'deleted', 'revision_id', 'language', 'delta', $field['field']]);
          $this->requireIntegers($table, ['entity_id', 'deleted', 'revision_id', 'delta']);
          $tables[$table] = $this->schema[$table];
        }
        elseif (in_array($table, ['location', 'location_instance'], TRUE)) {
          $locations = TRUE;
          if (!isset($field['expression'])) {
            $this->requireColumns($table, [$field['field']]);
          }
        }
        else {
          throw new \RuntimeException('Uncovered mapping dependency: ' . $table);
        }
        if (isset($field['expression'])) {
          if ($field['expression'] !== "CONCAT(location.latitude, ',', location.longitude)") {
            throw new \RuntimeException('Unsupported incremental expression dependency.');
          }
          $this->requireColumns('location', ['latitude', 'longitude']);
        }
        if (isset($field['join']) && (($field['join']['table'] ?? '') !== 'file_managed' || ($field['join']['key_field'] ?? '') !== 'fid')) {
          throw new \RuntimeException('Unsupported mapping join dependency.');
        }
        foreach ($field['joins'] ?? [] as $join) {
          $joinTable = $join['table'] ?? '';
          if (str_starts_with($joinTable, 'field_data_')) {
            $lidColumn = substr($joinTable, strlen('field_data_')) . '_lid';
            $this->requireColumns($joinTable, ['entity_type', 'entity_id', 'bundle', 'deleted', 'revision_id', 'language', 'delta', $lidColumn]);
            $this->requireIntegers($joinTable, ['entity_id', 'deleted', 'revision_id', 'delta', $lidColumn]);
            $tables[$joinTable] = $this->schema[$joinTable];
            $locations = TRUE;
          }
          elseif (!in_array($joinTable, ['node', 'location', 'location_instance'], TRUE)) {
            throw new \RuntimeException('Uncovered mapping join dependency.');
          }
        }
        if (isset($field['dynamic_paragraph'])) {
          throw new \RuntimeException('Paragraph dependencies require a dedicated incremental reader.');
        }
      }
    }
    foreach ($tables as $columns) {
      foreach (array_keys($columns) as $column) {
        if (str_ends_with($column, '_lid')) {
          $locations = TRUE;
        }
      }
    }
    if ($locations) {
      $this->requireColumns('location_instance', ['nid', 'vid', 'lid']);
      $this->requireIntegers('location_instance', ['nid', 'vid', 'lid']);
      $this->requireColumns('location', ['lid']);
      $this->requireIntegers('location', ['lid']);
    }
    if ($type === 'labdoo_story') {
      $this->requireColumns('field_data_field_story_section', ['entity_id', 'entity_type', 'bundle', 'deleted', 'revision_id', 'language', 'delta', 'field_story_section_value', 'field_story_section_revision_id']);
      $tables['field_data_field_story_section'] = $this->schema['field_data_field_story_section'];
      $this->requireColumns('field_collection_item', ['item_id', 'revision_id', 'field_name']);
      foreach (['heading' => ['field_story_heading_value'], 'text' => ['field_story_text_value', 'field_story_text_format'], 'picture' => ['field_story_picture_fid']] as $part => $columns) {
        $this->requireColumns('field_data_field_story_' . $part, array_merge(['entity_id', 'entity_type', 'bundle', 'deleted', 'revision_id', 'language', 'delta'], $columns));
      }
    }
    ksort($tables);
    return $this->typeSchemas[$type] = ['fields' => $tables, 'locations' => $locations, 'instances' => $instances, 'mapping' => $this->mappings[$type], 'version' => 1];
  }

  public function maxId(string $type): int {
    $this->coverage($type);
    $query = $this->source->select('node', 'n')->condition('type', $type)->where('(n.tnid = 0 OR n.nid = n.tnid)');
    $query->addExpression('MAX(n.nid)', 'maximum');
    return (int) $query->execute()->fetchField();
  }

  public function rootCount(string $type, int $ceiling): int {
    $this->coverage($type);
    return (int) $this->source->select('node', 'n')->condition('type', $type)->condition('nid', $ceiling, '<=')->where('(n.tnid = 0 OR n.tnid = n.nid)')->countQuery()->execute()->fetchField();
  }

  public function page(string $type, int $after, int $limit, int $ceiling): array {
    $this->coverage($type);
    if ($after < 0 || $ceiling < 0 || $limit < 1 || $limit > self::MAX_IDS) {
      throw new \InvalidArgumentException('Invalid keyset page bounds (limit 1..500).');
    }
    $rows = $this->source->select('node', 'n')->fields('n', ['nid', 'vid', 'changed', 'status', 'language'])->condition('type', $type)->condition('nid', $after, '>')->condition('nid', $ceiling, '<=')->where('(n.tnid = 0 OR n.nid = n.tnid)')->orderBy('nid')->range(0, $limit)->execute();
    $result = [];
    foreach ($rows as $row) {
      $result[(int) $row->nid] = $this->metadata((array) $row);
    }
    return $result;
  }

  private function metadata(array $row): array {
    return ['nid' => (int) $row['nid'], 'vid' => (int) $row['vid'], 'changed' => (int) $row['changed'], 'status' => (int) $row['status'], 'language' => (string) $row['language']];
  }

  public function existing(array $ids): array {
    $ids = $this->ids($ids);
    return $ids ? $this->source->select('node', 'n')->fields('n', ['nid', 'type'])->condition('nid', $ids, 'IN')->execute()->fetchAllKeyed() : [];
  }

  /** Root membership is separate from existence to detect root-to-translation changes. */
  public function rootIds(array $ids): array {
    $ids = $this->ids($ids);
    return $ids ? array_map('intval', $this->source->select('node', 'n')->fields('n', ['nid'])->condition('nid', $ids, 'IN')->where('(n.tnid = 0 OR n.tnid = n.nid)')->execute()->fetchCol()) : [];
  }

  /** Returns raw CURRENT payload, cached only for the latest bounded batch. */
  public function snapshots(string $type, array $ids): array {
    $ids = $this->ids($ids);
    $coverage = $this->coverage($type);
    $key = $type . ':' . implode(',', $ids);
    if ($key === $this->cacheKey && $this->cached !== NULL) {
      return $this->cached;
    }
    // Stream rows rather than buffering up to MAX_ROWS large text values in PDO.
    $client = $this->source->getClientConnection();
    $buffered = $client->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $client->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, FALSE);
    try {
      return $this->readSnapshot($type, $ids, $coverage, $key);
    }
    finally {
      $client->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
    }
  }

  private function readSnapshot(string $type, array $ids, array $coverage, string $key): array {
    $this->bytes = 0;
    $snapshot = ['nodes' => [], 'fields' => [], 'locations' => [], 'location_instances' => [], 'files' => [], 'story_sections' => [], 'translations' => [], 'roots' => [], 'coverage' => $coverage];
    if (!$ids) {
      return $snapshot;
    }
    $roots = $this->source->select('node', 'n')->fields('n')->condition('nid', $ids, 'IN')->condition('type', $type)->where('(n.tnid = 0 OR n.nid = n.tnid)')->execute();
    foreach ($roots as $root) {
      $snapshot['roots'][(int) $root->nid] = (array) $root;
    }
    if (!$snapshot['roots']) {
      return $snapshot;
    }
    $rootIds = array_keys($snapshot['roots']);
    $query = $this->source->select('node', 'n')->fields('n');
    $query->condition($query->orConditionGroup()->condition('nid', $rootIds, 'IN')->condition('tnid', $rootIds, 'IN'))->range(0, self::MAX_ROWS + 1);
    $families = [];
    $count = 0;
    foreach ($query->execute() as $node) {
      $row = (array) $node;
      $this->bound($row, ++$count);
      if ($row['type'] !== $type) {
        throw new \RuntimeException('Translation family contains a different node type.');
      }
      $nid = (int) $node->nid;
      $group = (int) $node->tnid ?: $nid;
      if (!isset($snapshot['roots'][$group])) {
        throw new \RuntimeException('Invalid Drupal 7 translation family.');
      }
      $snapshot['nodes'][$nid] = $row;
      $snapshot['translations'][$group][] = $row;
      $families[$nid] = $group;
    }
    $nodeIds = array_keys($snapshot['nodes']);
    $fileIds = [];
    $locationOwners = [];
    foreach ($coverage['fields'] as $table => $columns) {
      $snapshot['fields'][$table] = [];
      $query = $this->source->select($table, 'f')->fields('f')->condition('entity_type', 'node')->condition('entity_id', $nodeIds, 'IN')->condition('deleted', 0)->orderBy('entity_id')->orderBy('language')->orderBy('delta')->range(0, self::MAX_ROWS + 1);
      $count = 0;
      $seen = [];
      foreach ($query->execute() as $field) {
        $row = (array) $field;
        $this->bound($row, ++$count);
        if ($row['bundle'] !== $type) {
          throw new \RuntimeException('Current field data has a different bundle.');
        }
        $identity = $field->entity_id . ':' . $field->language . ':' . $field->delta;
        if (isset($seen[$identity])) {
          throw new \RuntimeException('Duplicate current field item: ' . $table);
        }
        $seen[$identity] = TRUE;
        $snapshot['fields'][$table][(int) $field->entity_id][] = $row;
        foreach ($row as $column => $value) {
          if (str_ends_with($column, '_fid') && (int) $value > 0) {
            $fileIds[(int) $value] = (int) $value;
          }
          if (str_ends_with($column, '_lid') && (int) $value > 0) {
            $locationOwners[(int) $value][(int) $field->entity_id] = TRUE;
          }
        }
      }
    }
    if ($coverage['locations']) {
      $lids = array_combine(array_keys($locationOwners), array_keys($locationOwners)) ?: [];
      $query = $this->source->select('location_instance', 'i')->fields('i');
      $query->innerJoin('node', 'n', 'n.nid = i.nid AND n.vid = i.vid');
      $query->condition('i.nid', $nodeIds, 'IN')->range(0, self::MAX_ROWS + 1);
      $count = 0;
      foreach ($query->execute() as $instance) {
        $row = (array) $instance;
        $this->bound($row, ++$count);
        $locationOwners[(int) $instance->lid][(int) $instance->nid] = TRUE;
        $snapshot['location_instances'][(int) $instance->nid][] = $row;
        $lids[(int) $instance->lid] = (int) $instance->lid;
      }
      if ($lids) {
        $foundLids = [];
        $query = $this->source->select('location', 'l')->fields('l')->condition('lid', array_values($lids), 'IN')->range(0, self::MAX_ROWS + 1);
        $count = 0;
        foreach ($query->execute() as $location) {
          $row = (array) $location;
          $this->bound($row, ++$count);
          $foundLids[(int) $location->lid] = TRUE;
          foreach (array_keys($locationOwners[(int) $location->lid]) as $nid) {
            $snapshot['locations'][$nid][] = $row;
          }
        }
        if (count($foundLids) !== count($lids)) {
          throw new \RuntimeException('Current location reference points to a missing record.');
        }
      }
    }
    if ($type === 'labdoo_story') {
      $this->storySections($snapshot, $fileIds);
    }
    if ($fileIds) {
      $this->requireColumns('file_managed', ['fid', 'uri', 'filename', 'filemime', 'filesize', 'status']);
      $query = $this->source->select('file_managed', 'f')->fields('f')->condition('fid', array_values($fileIds), 'IN')->range(0, self::MAX_ROWS + 1);
      $count = 0;
      foreach ($query->execute() as $file) {
        $row = (array) $file;
        $this->bound($row, ++$count);
        $snapshot['files'][(int) $file->fid] = $row;
      }
      // Missing files are represented explicitly by a NULL metadata record.
      foreach ($fileIds as $fid) {
        $snapshot['files'][$fid] ??= NULL;
      }
    }
    $this->cacheKey = $key;
    return $this->cached = $snapshot;
  }

  /** Batch-load current field collection rows referenced by story section VID. */
  private function storySections(array &$snapshot, array &$fileIds): void {
    $references = [];
    $items = [];
    foreach ($snapshot['nodes'] as $nid => $node) {
      $rows = $snapshot['fields']['field_data_field_story_section'][$nid] ?? [];
      $languages = array_unique(array_column($rows, 'language'));
      $language = in_array($node['language'], $languages, TRUE) ? $node['language'] : (in_array('und', $languages, TRUE) ? 'und' : NULL);
      if ($rows && $language === NULL) {
        throw new \RuntimeException('Story section references have no matching or neutral language.');
      }
      $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['language'] === $language));
      usort($rows, static fn(array $a, array $b): int => (int) $a['delta'] <=> (int) $b['delta']);
      $snapshot['story_sections'][$nid] = [];
      foreach ($rows as $row) {
        $vid = (int) $row['field_story_section_revision_id'];
        $itemId = (int) $row['field_story_section_value'];
        if ($vid < 1 || $itemId < 1 || (isset($references[$vid]) && $references[$vid] !== $itemId)) {
          throw new \RuntimeException('Invalid story field collection reference.');
        }
        $references[$vid] = $itemId;
        $items[$itemId] = $itemId;
      }
      $snapshot['story_reference_rows'][$nid] = $rows;
    }
    if (!$references) { return; }
    $metadata = [];
    $count = 0;
    $query = $this->source->select('field_collection_item', 'i')->fields('i')->condition('item_id', array_values($items), 'IN')->range(0, self::MAX_ROWS + 1);
    foreach ($query->execute() as $item) {
      $row = (array) $item;
      $this->bound($row, ++$count);
      if ($item->field_name !== 'field_story_section' || isset($metadata[(int) $item->item_id])) {
        throw new \RuntimeException('Invalid story collection metadata.');
      }
      $metadata[(int) $item->item_id] = $row;
    }
    $components = [];
    foreach (['heading', 'text', 'picture'] as $part) {
      $count = 0;
      $query = $this->source->select('field_data_field_story_' . $part, 'f')->fields('f')->condition('entity_type', 'field_collection_item')->condition('bundle', 'field_story_section')->condition('revision_id', array_keys($references), 'IN')->condition('deleted', 0)->range(0, self::MAX_ROWS + 1);
      foreach ($query->execute() as $component) {
        $row = (array) $component;
        $this->bound($row, ++$count);
        $vid = (int) $component->revision_id;
        if ((int) $component->entity_id !== $references[$vid] || (int) $component->delta !== 0 || isset($components[$vid][$part][$component->language])) {
          throw new \RuntimeException('Ambiguous story collection component.');
        }
        $components[$vid][$part][$component->language] = $row;
        if ($part === 'picture' && !empty($row['field_story_picture_fid'])) {
          $fid = (int) $row['field_story_picture_fid'];
          $fileIds[$fid] = $fid;
        }
      }
    }
    foreach ($snapshot['story_reference_rows'] as $nid => $rows) {
      foreach ($rows as $reference) {
        $vid = (int) $reference['field_story_section_revision_id'];
        $itemId = $references[$vid];
        if (!isset($metadata[$itemId])) {
          throw new \RuntimeException('Missing story field collection item.');
        }
        $section = ['revision_id' => $vid, 'delta' => (int) $reference['delta'], 'metadata' => $metadata[$itemId], 'source_rows' => $components[$vid] ?? []];
        foreach (['heading', 'text', 'picture'] as $part) {
          $candidates = $components[$vid][$part] ?? [];
          $section[$part] = $candidates[$snapshot['nodes'][$nid]['language']] ?? $candidates['und'] ?? NULL;
          if ($candidates && $section[$part] === NULL) {
            throw new \RuntimeException('Story component has no matching or neutral language.');
          }
        }
        if ($section['heading'] === NULL && $section['text'] === NULL && $section['picture'] === NULL && (int) $metadata[$itemId]['revision_id'] !== $vid) {
          throw new \RuntimeException('Referenced story revision is absent from current collection fields.');
        }
        if (!empty($section['picture']['field_story_picture_fid'])) {
          $fid = (int) $section['picture']['field_story_picture_fid'];
          $fileIds[$fid] = $fid;
        }
        $snapshot['story_sections'][$nid][] = $section;
      }
    }
    unset($snapshot['story_reference_rows']);
  }

  private function bound(array $row, int $count): void {
    $this->bytes += strlen(serialize($row));
    if ($count > self::MAX_ROWS || $this->bytes > self::MAX_BYTES) {
      throw new \RuntimeException('Source batch exceeds bounded reader capacity; reduce batch size.');
    }
  }

  /** Explicitly invalidate cached rows before re-reading a concurrently changed source. */
  public function refresh(): void {
    $this->cached = NULL;
    $this->cacheKey = '';
    $this->typeSchemas = [];
  }

  /** Stable across PDO integer/string fetch modes; preserves NULL and binary data. */
  private function canonical(array $row, array $columns = []): string {
    ksort($row);
    foreach ($row as $column => &$value) {
      if ($value === NULL) {
        continue;
      }
      $kind = $columns[$column] ?? '';
      if (in_array($kind, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'numeric'], TRUE)) {
        $text = (string) $value;
        if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $text)) {
          throw new \RuntimeException('Unexpected numeric source representation.');
        }
        $negative = str_starts_with($text, '-');
        $parts = explode('.', ltrim($text, '-'), 2);
        $value = ltrim($parts[0], '0') ?: '0';
        $fraction = rtrim($parts[1] ?? '', '0');
        $value .= $fraction !== '' ? '.' . $fraction : '';
        if ($negative && $value !== '0') {
          $value = '-' . $value;
        }
      }
      elseif (in_array($kind, ['float', 'double', 'real'], TRUE)) {
        if (!is_numeric($value) || !is_finite((float) $value)) {
          throw new \RuntimeException('Invalid floating source value.');
        }
        $value = str_replace(',', '.', sprintf('%.17g', (float) $value));
      }
      else {
        $value = base64_encode((string) $value);
      }
    }
    return json_encode($row, JSON_THROW_ON_ERROR);
  }

  private function rowsHash(array $rows, string $table): string {
    $normalized = array_map(fn(array $row): string => $this->canonical($row, $this->schema[$table] ?? []), $rows);
    sort($normalized, SORT_STRING);
    return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
  }

  public function fingerprints(string $type, array $ids): array {
    // Apply-time verification must never reuse the planning snapshot.
    $this->refresh();
    $snapshot = $this->snapshots($type, $ids);
    $result = [];
    foreach ($snapshot['roots'] as $nid => $root) {
      $family = $snapshot['translations'][$nid] ?? [];
      $coverage = $snapshot['coverage'];
      $coverage['instances'] = $this->rowsHash($coverage['instances'], 'field_config_instance');
      $parts = ['version' => 1, 'mapping' => $coverage, 'nodes' => $this->rowsHash($family, 'node')];
      $files = [];
      foreach ($family as $node) {
        $member = (int) $node['nid'];
        foreach ($snapshot['fields'] as $table => $byId) {
          $rows = $byId[$member] ?? [];
          $parts['fields'][$table][$member] = $this->rowsHash($rows, $table);
          foreach ($rows as $row) {
            foreach ($row as $column => $value) {
              if (str_ends_with($column, '_fid') && (int) $value > 0) {
                $files[(int) $value] = $snapshot['files'][(int) $value] === NULL ? NULL : $this->canonical($snapshot['files'][(int) $value], $this->schema['file_managed']);
              }
            }
          }
        }
        foreach ($snapshot['story_sections'][$member] ?? [] as $position => $section) {
          $sectionHash = ['revision_id' => $section['revision_id'], 'delta' => $section['delta'], 'metadata' => $this->canonical($section['metadata'], $this->schema['field_collection_item'])];
          foreach (['heading', 'text', 'picture'] as $part) {
            $sectionHash[$part] = $this->rowsHash(array_values($section['source_rows'][$part] ?? []), 'field_data_field_story_' . $part);
          }
          $parts['story_sections'][$member][$position] = $sectionHash;
          if (!empty($section['picture']['field_story_picture_fid'])) {
            $fid = (int) $section['picture']['field_story_picture_fid'];
            $files[$fid] = $snapshot['files'][$fid] === NULL ? NULL : $this->canonical($snapshot['files'][$fid], $this->schema['file_managed']);
          }
          foreach ($section['source_rows']['picture'] ?? [] as $picture) {
            if (!empty($picture['field_story_picture_fid'])) {
              $fid = (int) $picture['field_story_picture_fid'];
              $files[$fid] = $snapshot['files'][$fid] === NULL ? NULL : $this->canonical($snapshot['files'][$fid], $this->schema['file_managed']);
            }
          }
        }
        $parts['locations'][$member] = $this->rowsHash($snapshot['locations'][$member] ?? [], 'location');
        $parts['location_instances'][$member] = $this->rowsHash($snapshot['location_instances'][$member] ?? [], 'location_instance');
      }
      ksort($files);
      $parts['files'] = $files;
      // Normalize nested key order as well as SQL row order.
      $sort = function (&$item) use (&$sort): void {
        if (is_array($item)) {
          ksort($item);
          foreach ($item as &$value) {
            $sort($value);
          }
        }
      };
      $sort($parts);
      $result[$nid] = $this->metadata($root) + ['languages' => array_values(array_unique(array_column($family, 'language'))), 'hash' => hash('sha256', serialize($parts))];
    }
    return $result;
  }

}
