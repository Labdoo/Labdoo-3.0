<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Database\Connection;
use Drupal\node\NodeInterface;

/**
 * Node-only incremental adapter. Projection is SELECT-only and never transforms
 * paragraphs/media. Unsupported data blocks writes, including incomplete legacy
 * mappings. The engine owns transactions, checkpoints and its global lock.
 */
final class NodeSyncAdapter {
  use \Drupal\labdoo_migrate\Traits\TextFormatMapperTrait;
  private array $mappings = [];
  private NodeSourceReader $reader;
  private array $snapshot = [];
  private string $snapshotType = '';
  private const VERSION = 'node-projection-v1';

  public function __construct(private Connection $source, private Connection $destination, ?NodeSourceReader $reader = NULL) {
    $this->reader = $reader ?? new NodeSourceReader($source);
    foreach (glob(dirname(__DIR__, 3) . '/config/fields_mapping/*.json') as $path) {
      $config = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
      if (($config['entity_type'] ?? '') === 'node' && !empty($config['source_type'])) {
        $this->mappings[$config['source_type']] = $config;
      }
    }
  }

  public function context(string $type): array {
    $mapping = $this->mappings[$type] ?? NULL;
    if (!$mapping || count($mapping['destination_types'] ?? []) !== 1) {
      throw new \InvalidArgumentException('Unsupported incremental node type: ' . $type);
    }
    $semantics = ['version' => self::VERSION, 'mapping' => $mapping, 'global' => json_decode(file_get_contents(dirname(__DIR__, 3) . '/config/fields_mapping/global.json'), TRUE, 512, JSON_THROW_ON_ERROR), 'default_language' => \Drupal::languageManager()->getDefaultLanguage()->getId(), 'code' => []];
    foreach ([__FILE__, __DIR__ . '/NodeSourceReader.php', __DIR__ . '/StorySectionAdapter.php', __DIR__ . '/ManagedFileIdentity.php', __DIR__ . '/ReadOnlyEntityLoader.php', __DIR__ . '/DerivedFields.php'] as $path) { $semantics['code'][basename($path)] = hash_file('sha256', $path); }
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $mapping['destination_types'][0]);
    foreach ($definitions as $name => $definition) {
      if (!$definition->isComputed()) {
        $semantics['destination_fields'][$name] = ['type' => $definition->getType(), 'translatable' => $definition->isTranslatable(), 'required' => $definition->isRequired(), 'cardinality' => $definition->getFieldStorageDefinition()->getCardinality(), 'settings' => $definition->getSettings()];
      }
    }
    return ['bundle' => $mapping['destination_types'][0], 'mapping_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR))];
  }

  /** Resolve identity only from evidence, never from equal numeric IDs. */
  public function destinations(string $type, array $sourceIds): array {
    $bundle = $this->context($type)['bundle'];
    if (!$sourceIds) { return []; }
    if (count($sourceIds) > 500) { throw new \InvalidArgumentException('Batch exceeds 500.'); }
    $this->snapshot = $this->reader->snapshots($type, $sourceIds);
    $this->snapshotType = $type;
    $matches = [];
    if ($this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
      $rows = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['source_id', 'destination_id'])->condition('entity_type', 'node')->condition('bundle', $bundle)->condition('source_id', $sourceIds, 'IN')->execute();
      foreach ($rows as $row) { $matches[(int) $row->source_id][(int) $row->destination_id] = TRUE; }
    }
    if ($this->destination->schema()->tableExists('node__field_d7_nid')) {
      $rows = $this->destination->select('node__field_d7_nid', 'f')->fields('f', ['entity_id', 'field_d7_nid_value'])->condition('deleted', 0)->condition('field_d7_nid_value', $sourceIds, 'IN')->execute();
      foreach ($rows as $row) { $matches[(int) $row->field_d7_nid_value][(int) $row->entity_id] = TRUE; }
    }
    $ids = [];
    foreach ($matches as $list) { $ids = array_merge($ids, array_keys($list)); }
    $nodes = ReadOnlyEntityLoader::storage('node')->loadMultiple(array_unique(array_merge($ids, array_map('intval', $sourceIds))));
    $unreconciledActions = FALSE;
    if ($type === 'action') {
      $query = $this->destination->select('node', 'n')->fields('n', ['nid'])->condition('n.type', 'action');
      if ($this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
        $query->leftJoin('labdoo_migrate_tracking', 't', "t.destination_id=n.nid AND t.entity_type='node' AND t.bundle='action'");
        $query->isNull('t.source_id');
      }
      if ($this->destination->schema()->tableExists('node__field_d7_nid')) {
        $query->leftJoin('node__field_d7_nid', 'f', 'f.entity_id=n.nid AND f.deleted=0 AND f.field_d7_nid_value>0');
        $query->isNull('f.entity_id');
      }
      $unreconciledActions = (bool) $query->range(0, 1)->execute()->fetchField();
    }
    $out = [];
    foreach ($sourceIds as $sourceId) {
      $candidates = array_keys($matches[$sourceId] ?? []);
      $id = count($candidates) === 1 ? $candidates[0] : NULL;
      $conflict = count($candidates) > 1 ? 'Ambiguous migration identity' : NULL;
      if (!$candidates && isset($nodes[(int) $sourceId])) { $conflict = 'Source NID occupied without proven migration identity'; }
      if (!$candidates && $unreconciledActions) { $conflict = 'unreconciled_regenerated_actions'; }
      if ($id && (!isset($nodes[$id]) || $nodes[$id]->bundle() !== $bundle)) { $conflict = 'Tracked destination missing or wrong bundle'; }
      $out[(int) $sourceId] = ['id' => $id, 'hash' => $id && isset($nodes[$id]) ? $this->hashNode($nodes[$id]) : NULL, 'conflict' => $conflict, 'published' => $id && isset($nodes[$id]) ? $this->published($nodes[$id]) : NULL];
    }
    return $out;
  }

  public function trackedPage(string $type, int $after, int $limit): array {
    $bundle = $this->context($type)['bundle'];
    if ($limit < 1 || $limit > 500 || $after < 0) { throw new \InvalidArgumentException('Invalid tracked page.'); }
    $ids = [];
    if ($this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
      $ids = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['source_id'])->condition('entity_type', 'node')->condition('bundle', $bundle)->condition('source_id', $after, '>')->orderBy('source_id')->range(0, $limit)->execute()->fetchCol();
    }
    if ($this->destination->schema()->tableExists('node__field_d7_nid')) {
      $q = $this->destination->select('node__field_d7_nid', 'f');
      $q->innerJoin('node', 'n', 'n.nid=f.entity_id');
      $ids = array_merge($ids, $q->fields('f', ['field_d7_nid_value'])->condition('n.type', $bundle)->condition('f.deleted', 0)->condition('field_d7_nid_value', $after, '>')->orderBy('field_d7_nid_value')->range(0, $limit)->execute()->fetchCol());
    }
    $ids = array_unique(array_map('intval', $ids)); sort($ids, SORT_NUMERIC);
    return $this->destinations($type, array_slice($ids, 0, $limit));
  }

  public function hashes(string $type, array $destinationIds): array {
    $bundle = $this->context($type)['bundle'];
    $storage = ReadOnlyEntityLoader::storage('node');
    $out = [];
    foreach ($storage->loadMultiple($destinationIds) as $id => $node) {
      if ($node->bundle() !== $bundle) { throw new \RuntimeException('Destination bundle changed.'); }
      $out[$id] = $this->hashNode($node);
    }
    return $out;
  }

  private function published(NodeInterface $node): bool {
    foreach ($node->getTranslationLanguages() as $lang => $language) {
      if ($node->getTranslation($lang)->isPublished()) { return TRUE; }
    }
    return FALSE;
  }

  /** Capture both sides so derived counters also repair removed relationships. */
  private function relations(?NodeInterface $node): array {
    $out = ['hub' => [], 'edoovillage' => [], 'dootrip' => [], 'dootronic' => []];
    if (!$node || $node->isNew()) { return $out; }
    if (isset($out[$node->bundle()])) { $out[$node->bundle()][] = (int) $node->id(); }
    $ids = [];
    foreach ($node->getTranslationLanguages() as $lang => $language) {
      foreach ($node->getTranslation($lang)->getFields(FALSE) as $field) {
        if ($field->getFieldDefinition()->getType() !== 'entity_reference' || $field->getFieldDefinition()->getSetting('target_type') !== 'node') { continue; }
        foreach ($field->getValue() as $item) {
          if (!empty($item['target_id'])) { $ids[(int) $item['target_id']] = (int) $item['target_id']; }
        }
      }
    }
    if ($ids) {
      foreach (ReadOnlyEntityLoader::storage('node')->loadMultiple($ids) as $id => $related) {
        if (isset($out[$related->bundle()])) { $out[$related->bundle()][] = (int) $id; }
      }
    }
    foreach ($out as &$list) { $list = array_values(array_unique($list)); sort($list, SORT_NUMERIC); }
    unset($list);
    return $out;
  }

  private function hashNode(NodeInterface $node): string {
    $names = ['status', 'field_d7_nid'];
    foreach ($this->mappings as $mapping) {
      if (in_array($node->bundle(), $mapping['destination_types'], TRUE)) {
        foreach ($mapping['fields_mapping'] as $field) { $names[] = $field['destination']['field']; }
      }
    }
    $names = array_diff(array_unique($names), array_merge(
      ['nid', 'vid', 'changed', 'revision_timestamp', 'revision_uid', 'revision_log'],
      DerivedFields::computedFields($node->bundle())
    ));
    $data = ['identity' => ['id' => (int) $node->id(), 'bundle' => $node->bundle(), 'uuid' => $node->uuid(), 'default_language' => $node->getUntranslated()->language()->getId()], 'translations' => []];
    foreach ($node->getTranslationLanguages() as $lang => $language) {
      $translation = $node->getTranslation($lang);
      $data['translations'][$lang] = [];
      foreach ($names as $name) {
        if (!$translation->hasField($name)) { continue; }
        $field = $translation->get($name);
        $definition = $field->getFieldDefinition();
        if ($definition->isComputed()) { continue; }
        $properties = match ($definition->getType()) {
          'address' => ['country_code', 'locality', 'postal_code', 'address_line1', 'address_line2', 'dependent_locality', 'administrative_area', 'sorting_code', 'organization', 'given_name', 'additional_name', 'family_name'],
          'file' => ['target_id', 'description', 'display'],
          'image' => ['target_id', 'alt', 'title'],
          'text', 'text_long' => ['value', 'format'],
          'text_with_summary' => ['value', 'format', 'summary'],
          default => [$definition->getFieldStorageDefinition()->getMainPropertyName()],
        };
        $data['translations'][$lang][$name] = [];
        foreach ($field->getValue() as $item) {
          $owned = [];
          foreach ($properties as $property) { $owned[$property] = (string) ($item[$property] ?? ''); }
          $data['translations'][$lang][$name][] = $owned;
        }
      }
      if ($node->bundle() === 'labdoo_story' && $translation->hasField('field_story_section')) {
        $data['translations'][$lang]['@story_sections'] = [];
        foreach ($translation->get('field_story_section') as $item) {
          $data['translations'][$lang]['@story_sections'][] = (new StorySectionAdapter())->actual(ReadOnlyEntityLoader::paragraph($item));
        }
      }
      ksort($data['translations'][$lang]);
    }
    ksort($data['translations']);
    return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  public function compare(string $type, int $sourceId, int $destinationId, array $baselineSourceLanguages = []): array {
    $node = ReadOnlyEntityLoader::storage('node')->load($destinationId);
    if (!$node || $node->bundle() !== $this->context($type)['bundle']) { throw new \RuntimeException('Missing or wrong destination node.'); }
    [$values, $unsupported] = $this->projection($type, $sourceId, $node);
    $differences = [];
    foreach ($values as $lang => $fields) {
      if (!$node->hasTranslation($lang)) { $differences[] = $lang . ':translation_missing'; continue; }
      $translation = $node->getTranslation($lang);
      foreach ($fields as $field => $desired) {
        if ($field === '@story_sections') {
          if (!(new StorySectionAdapter())->equal($translation, $desired)) { $differences[] = $lang . ':field_story_section'; }
          continue;
        }
        if (!$this->equivalent($translation->get($field)->getValue(), $desired)) { $differences[] = $lang . ':' . $field; }
      }
    }
    foreach (array_keys($node->getTranslationLanguages()) as $lang) {
      if (!isset($values[$lang])) {
        if (in_array($lang, $baselineSourceLanguages, TRUE) && $lang !== $node->getUntranslated()->language()->getId()) { $differences[] = $lang . ':translation_removed'; }
        else { $unsupported[] = 'Destination-only or default translation: ' . $lang; }
      }
    }
    return ['equal' => !$differences && !$unsupported, 'differences' => array_values(array_unique($differences)), 'unsupported' => array_values(array_unique($unsupported))];
  }

  /** Compare only source-owned item properties, preserving auxiliary metadata. */
  private function equivalent(array $actual, array $desired): bool {
    if (count($actual) !== count($desired)) { return FALSE; }
    foreach ($desired as $delta => $item) {
      foreach ($item as $key => $value) {
        if ($key === '@source_file') { return FALSE; }
        if ($key === 'value' && is_string($value) && preg_match('/^POINT\s*\(\s*([-+0-9.eE]+)\s+([-+0-9.eE]+)\s*\)$/', $value, $wanted) && preg_match('/^POINT\s*\(\s*([-+0-9.eE]+)\s+([-+0-9.eE]+)\s*\)$/', (string) ($actual[$delta][$key] ?? ''), $found)) {
          if (abs((float) $wanted[1] - (float) $found[1]) > 0.0000001 || abs((float) $wanted[2] - (float) $found[2]) > 0.0000001) { return FALSE; }
          continue;
        }
        if ((string) ($actual[$delta][$key] ?? '') !== (string) $value) { return FALSE; }
      }
    }
    return TRUE;
  }

  /** Pure mapping projection. Unknown transformations fail closed. */
  private function projection(string $type, int $sourceId, NodeInterface $node): array {
    $this->context($type);
    $snapshot = $this->snapshotType === $type && isset($this->snapshot['translations'][$sourceId]) ? $this->snapshot : $this->reader->snapshots($type, [$sourceId]);
    $family = $snapshot['translations'][$sourceId] ?? [];
    if (!$family) { throw new \RuntimeException('Missing source root.'); }
    $out = []; $unsupported = [];
    foreach ($family as $sourceNode) {
      $nid = (int) $sourceNode['nid'];
      $lang = $sourceNode['language'];
      if (!$lang || $lang === 'und') { $lang = \Drupal::languageManager()->getDefaultLanguage()->getId(); }
      if (!\Drupal::languageManager()->getLanguage($lang)) { $unsupported[] = 'Unconfigured source language: ' . $lang; continue; }
      if (isset($out[$lang])) { $unsupported[] = 'Duplicate source language: ' . $lang; continue; }
      $out[$lang] = [];
      $target = $node->hasTranslation($lang) ? $node->getTranslation($lang) : $node;
      foreach ($this->mappings[$type]['fields_mapping'] as $mapping) {
        $destination = $mapping['destination']; $name = $destination['field'];
        if (in_array($name, array_merge(['nid', 'changed', 'vid', 'revision_timestamp', 'revision_uid', 'revision_log'], DerivedFields::computedFields($node->bundle())), TRUE)) { continue; }
        if (!$target->hasField($name)) { $unsupported[] = 'Missing destination field: ' . $name; continue; }
        $special = $destination['special_type'] ?? [];
        $metadata = is_array($special) ? ($special['metadata'] ?? []) : ($destination['metadata'] ?? []);
        $special = is_array($special) ? ($special['type'] ?? '') : $special;
        $fields = $mapping['source']['fields'] ?? [];
        if (count($fields) !== 1 || !empty($mapping['source']['dynamic_paragraphs'])) { $unsupported[] = 'Complex mapping: ' . $name; continue; }
        $source = $fields[0]; $table = $source['table']; $column = $source['field'];
        if (!empty($source['dynamic_paragraph']) || !empty($source['taxonomy']) || !empty($source['default_value'])) { $unsupported[] = 'Complex source: ' . $name; continue; }
        $rows = $table === 'node' ? [$sourceNode] : ($table === 'location' ? ($snapshot['locations'][$nid] ?? []) : ($snapshot['fields'][$table][$nid] ?? []));
        // Dootrip origin/destination use separate source location fields.
        if ($table === 'location' && in_array($name, ['field_origin_of_the_trip', 'field_destination_of_the_trip'], TRUE)) {
          $lids = array_column($snapshot['fields']['field_data_' . $name][$nid] ?? [], $name . '_lid');
          $rows = array_values(array_filter($rows, static fn($row) => in_array($row['lid'], $lids)));
        }
        if (str_starts_with($table, 'field_data_')) {
          $byDelta = [];
          foreach ($rows as $row) {
            $rowLanguage = $row['language'] ?? 'und';
            if (!in_array($rowLanguage, ['und', $sourceNode['language'], $lang], TRUE)) { continue; }
            $delta = (int) ($row['delta'] ?? 0);
            if (!isset($byDelta[$delta]) || $rowLanguage !== 'und') { $byDelta[$delta] = $row; }
          }
          ksort($byDelta, SORT_NUMERIC); $rows = array_values($byDelta);
        }
        if ($table === 'location' && $special === 'address_component' && count(array_unique(array_column($rows, $column))) > 1) { $unsupported[] = 'Ambiguous current address: ' . $name; continue; }
        $definition = $target->get($name)->getFieldDefinition();
        if ($table === 'node' && in_array($name, ['created', 'uid'], TRUE) && !$definition->isTranslatable()) { $rows = [$snapshot['roots'][$sourceId]]; }
        $fieldType = $definition->getType();
        $main = $definition->getFieldStorageDefinition()->getMainPropertyName();
        $items = [];
        foreach ($rows as $row) {
          if ($table !== 'node' && isset($row['language']) && !in_array($row['language'], ['und', $sourceNode['language'], $lang], TRUE)) { continue; }
          $value = $row[$column] ?? NULL;
          if ($special === 'managed_file') {
            foreach ($row as $key => $raw) { if (str_ends_with($key, '_fid')) { $value = $raw; break; } }
          }
          if ($special === 'geolocation') {
            $lat = (float) ($row['latitude'] ?? 0); $lon = (float) ($row['longitude'] ?? 0);
            if (!$lat && !$lon) { continue; }
            if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) { $unsupported[] = 'Invalid geometry: ' . $name; continue; }
            $items[] = ['value' => 'POINT (' . $lon . ' ' . $lat . ')']; continue;
          }
          if ($fieldType === 'address' && $table === 'location' && $special !== 'address_component') {
            $address = ['country_code' => strtoupper($row['country'] ?? ''), 'locality' => $row['city'] ?? '', 'postal_code' => $row['postal_code'] ?? '', 'address_line1' => $row['street'] ?? ''];
            if (array_filter($address, static fn($value) => $value !== '')) { $items[] = $address; }
            continue;
          }
          if ($value === NULL || $value === '') { continue; }
          if ($special === 'address_component') {
            $component = $metadata['component'] ?? '';
            if (!$component) { $unsupported[] = 'Unknown address component'; continue; }
            $out[$lang][$name][0][$component] = $component === 'country_code' ? strtoupper($value) : $value;
            continue;
          }
          if ($special === 'watt_hours') { $value = str_replace('wh', '', strtolower($value)); }
          elseif ($special === 'managed_file') {
            $fidKey = preg_replace('/_value$|_uri$/', '_fid', $column);
            foreach ($row as $key => $raw) { if (str_ends_with($key, '_fid')) { $fidKey = $key; break; } }
            $file = $snapshot['files'][(int) ($row[$fidKey] ?? 0)] ?? NULL;
            $uri = $file['uri'] ?? NULL;
            if (!$uri) { $unsupported[] = 'Unresolved source file: ' . $name; continue; }
            $item = (new ManagedFileIdentity($this->destination))->project($file);
            if ($fieldType === 'image') {
              $prefix = preg_replace('/_fid$/', '', $fidKey);
              $item['alt'] = $row[$prefix . '_alt'] ?? '';
              $item['title'] = $row[$prefix . '_title'] ?? '';
            }
            $items[] = $item; continue;
          }
          elseif (!in_array($special, ['', 'formatted_text', 'formatted_text_with_replacement'], TRUE)) { $unsupported[] = 'Unsupported transformation: ' . $name; continue; }
          if ($fieldType === 'entity_reference') {
            $targetType = $definition->getSetting('target_type');
            $resolved = $this->reference($targetType, (int) $value);
            if ($resolved === NULL) { $unsupported[] = 'Unresolved ' . $targetType . ' reference: ' . $name; continue; }
            $items[] = ['target_id' => $resolved];
          }
          elseif (in_array($fieldType, ['text', 'text_long', 'text_with_summary'], TRUE)) {
            if ($special === 'formatted_text_with_replacement') { $value = str_replace($metadata['search'] ?? '', $metadata['replace'] ?? '', $value); }
            $prefix = preg_replace('/_value$/', '', $column);
            $format = $metadata['format'] ?? $this->mapFormat($row[$prefix . '_format'] ?? NULL);
            $allowed = $definition->getSetting('allowed_formats') ?? [];
            if ($allowed && !in_array($format, $allowed, TRUE)) { $format = reset($allowed); }
            $item = ['value' => $value, 'format' => $format];
            if ($fieldType === 'text_with_summary') {
              $summary = $row[$prefix . '_summary'] ?? '';
              if (!$summary && $definition->getSetting('required_summary')) { $summary = mb_substr(trim(strip_tags($value)), 0, 600); }
              $item['summary'] = $summary;
            }
            $items[] = $item;
          }
          elseif (in_array($fieldType, ['string', 'string_long', 'integer', 'decimal', 'float', 'boolean', 'list_string', 'list_integer', 'email', 'datetime', 'timestamp', 'created', 'changed', 'address_country'], TRUE)) {
            if (in_array($fieldType, ['integer', 'list_integer', 'boolean', 'timestamp', 'created', 'changed', 'decimal', 'float'], TRUE)) {
              if (!is_numeric($value) || !is_finite((float) $value)) { $unsupported[] = 'Invalid numeric source: ' . $name; continue; }
              if (in_array($fieldType, ['integer', 'list_integer', 'boolean', 'timestamp', 'created', 'changed'], TRUE)) {
                if ((float) $value !== (float) (int) $value) { $unsupported[] = 'Fractional integer source: ' . $name; continue; }
                $value = (int) $value;
              }
              elseif ($fieldType === 'decimal') { $value = number_format((float) $value, (int) $definition->getSetting('scale'), '.', ''); }
              else { $value = (float) $value; }
            }
            if ($fieldType === 'datetime') { $value = $definition->getSetting('datetime_type') === 'date' ? substr($value, 0, 10) : str_replace(' ', 'T', $value); }
            if ($fieldType === 'address_country') { $value = strtoupper($value); }
            $items[] = [$main => $value];
          }
          else { $unsupported[] = 'Unsupported field type: ' . $name . ':' . $fieldType; }
        }
        $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
        if ($cardinality > 0 && count($items) > $cardinality) { $unsupported[] = 'Source cardinality exceeds destination: ' . $name; }
        if ($special !== 'address_component') { $out[$lang][$name] = $items; }
        elseif (!isset($out[$lang][$name])) { $out[$lang][$name] = []; }
      }
      // Publication is source-owned even where older JSON mappings omitted it.
      $out[$lang]['status'] = [['value' => (int) $sourceNode['status']]];
      if ($node->hasField('field_d7_nid')) { $out[$lang]['field_d7_nid'] = [['value' => $sourceId]]; }
      if ($type === 'labdoo_story') {
        try { $out[$lang]['@story_sections'] = (new StorySectionAdapter())->desired($snapshot, $nid); }
        catch (\RuntimeException $e) { $unsupported[] = $e->getMessage(); }
      }
    }
    $shared = [];
    foreach ($out as $lang => $fields) {
      foreach ($fields as $name => $items) {
        $targetName = $name === '@story_sections' ? 'field_story_section' : $name;
        if (!$node->hasField($targetName) || $node->get($targetName)->getFieldDefinition()->isTranslatable()) { continue; }
        if (isset($shared[$name]) && json_encode($shared[$name], JSON_THROW_ON_ERROR) !== json_encode($items, JSON_THROW_ON_ERROR)) { $unsupported[] = 'Conflicting source values for shared field: ' . $name; }
        $shared[$name] = $items;
      }
    }
    return [$out, array_values(array_unique($unsupported))];
  }

  private function reference(string $targetType, int $sourceId): ?int {
    if (!$sourceId) { return $targetType === 'user' ? 0 : NULL; }
    if ($targetType === 'node') {
      $sourceNode = $this->source->select('node', 'n')->fields('n', ['nid', 'tnid', 'type'])->condition('nid', $sourceId)->execute()->fetchAssoc();
      if (!$sourceNode) { return NULL; }
      if ((int) $sourceNode['tnid'] > 0 && (int) $sourceNode['tnid'] !== $sourceId) {
        $root = $this->source->select('node', 'n')->fields('n', ['nid', 'type'])->condition('nid', (int) $sourceNode['tnid'])->execute()->fetchAssoc();
        if (!$root || $root['type'] !== $sourceNode['type']) { return NULL; }
        $sourceId = (int) $root['nid'];
      }
      $rows = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['destination_id'])->condition('entity_type', 'node')->condition('source_id', $sourceId)->execute()->fetchCol();
      if ($this->destination->schema()->tableExists('node__field_d7_nid')) {
        $rows = array_merge($rows, $this->destination->select('node__field_d7_nid', 'f')->fields('f', ['entity_id'])->condition('deleted', 0)->condition('field_d7_nid_value', $sourceId)->execute()->fetchCol());
      }
      $rows = array_unique(array_map('intval', $rows));
      return count($rows) === 1 && ReadOnlyEntityLoader::storage('node')->load(reset($rows)) ? reset($rows) : NULL;
    }
    if ($targetType === 'user') {
      $rows = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['destination_id'])->condition('entity_type', 'user')->condition('source_id', $sourceId)->execute()->fetchCol();
      if ($this->destination->schema()->tableExists('migrate_map_d7_user')) {
        $rows = array_merge($rows, $this->destination->select('migrate_map_d7_user', 'm')->fields('m', ['destid1'])->condition('sourceid1', $sourceId)->execute()->fetchCol());
      }
      $rows = array_unique(array_map('intval', $rows));
      return count($rows) === 1 && ReadOnlyEntityLoader::storage('user')->load(reset($rows)) ? reset($rows) : NULL;
    }
    return NULL;
  }

  public function apply(string $type, int $sourceId, ?int $destinationId, string $operation, array $expected): array {
    $context = $this->context($type);
    $removeLanguages = [];
    if (!hash_equals($context['mapping_hash'], $expected['mapping_hash'] ?? '')) { throw new \RuntimeException('Mapping changed since planning.'); }
    if (!in_array($operation, ['create', 'update', 'unpublish'], TRUE)) { throw new \InvalidArgumentException('Unsupported node operation.'); }
    if (($operation === 'create' && $destinationId !== NULL) || ($operation !== 'create' && !$destinationId)) { throw new \InvalidArgumentException('Operation does not match destination identity.'); }
    $identity = $this->destinations($type, [$sourceId])[$sourceId];
    if ($identity['conflict'] || $identity['id'] !== $destinationId) { throw new \RuntimeException('Migration identity changed or is ambiguous.'); }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    if ($destinationId) { $storage->resetCache([$destinationId]); }
    $node = $destinationId ? $storage->load($destinationId) : NULL;
    if ($destinationId && (!$node || $node->bundle() !== $context['bundle'])) { throw new \RuntimeException('Destination missing or wrong bundle.'); }
    $actualHash = $node ? $this->hashNode($node) : NULL;
    if ($actualHash !== (($expected['destination_hash'] ?? '') ?: NULL)) { throw new \RuntimeException('Destination changed since planning.'); }
    $fingerprints = $this->reader->fingerprints($type, [$sourceId]);
    $this->snapshot = []; $this->snapshotType = '';
    if ($operation === 'unpublish') {
      if (isset($fingerprints[$sourceId]) || $this->reader->existing([$sourceId]) || !$node) { throw new \RuntimeException('Unpublish requires absent source and existing destination.'); }
      if (!$this->published($node)) { return ['destination_id' => (int) $node->id(), 'destination_hash' => $actualHash, 'written' => FALSE, 'effects' => []]; }
      $values = [];
      foreach ($node->getTranslationLanguages() as $lang => $language) { $values[$lang]['status'] = [['value' => 0]]; }
    }
    else {
      if (($fingerprints[$sourceId]['hash'] ?? NULL) !== ($expected['source_hash'] ?? NULL)) { throw new \RuntimeException('Source changed since planning.'); }
      if (!$node) {
        if ($operation !== 'create' || ReadOnlyEntityLoader::storage('node')->load($sourceId)) { throw new \RuntimeException('Source NID occupied; refusing ID displacement.'); }
        $snapshot = $this->reader->snapshots($type, [$sourceId]);
        $root = $snapshot['nodes'][$sourceId] ?? NULL;
        $lang = $root['language'] ?? 'en';
        if ($lang === 'und' || !$lang) { $lang = \Drupal::languageManager()->getDefaultLanguage()->getId(); }
        $node = $storage->create(['type' => $context['bundle'], 'nid' => $sourceId, 'langcode' => $lang]);
      }
      [$values, $unsupported] = $this->projection($type, $sourceId, $node);
      foreach (array_keys($node->getTranslationLanguages()) as $lang) {
        if (!isset($values[$lang])) {
          if (in_array($lang, $expected['source_languages'] ?? [], TRUE) && $lang !== $node->getUntranslated()->language()->getId()) { $removeLanguages[] = $lang; }
          else { $unsupported[] = 'Destination-only or default translation: ' . $lang; }
        }
      }
      if ($unsupported) { throw new \RuntimeException('Unsupported incremental data: ' . implode('; ', $unsupported)); }
    }
    $relationsBefore = $this->relations($node);
    $originalForTracking = $node->isNew() ? NULL : clone $node;
    $state = \Drupal::state(); $sentinel = new \stdClass();
    $prior = $state->get('labdoo_migrate_is_running', $sentinel);
    $account = ReadOnlyEntityLoader::storage('user')->load(1);
    if (!$account) { throw new \RuntimeException('A trusted migration administrator (UID 1) is required to validate imported text formats.'); }
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($account);
    try {
      $state->set('labdoo_migrate_is_running', TRUE);
      $node->original_entity_id = $sourceId;
      $node->labdoo_skip_geocoding = TRUE;
      $node->skip_geocoding_enqueue = TRUE;
      $node->skip_recompute_enqueue = TRUE;
      $node->skip_action_generation = TRUE;
      $node->search_api_skip_tracking = TRUE;
      foreach ($removeLanguages as $lang) { $node->removeTranslation($lang); }
      foreach ($values as $lang => $fields) {
        $translation = $node->hasTranslation($lang) ? $node->getTranslation($lang) : $node->addTranslation($lang);
        foreach ($fields as $field => $value) {
          if ($field === '@story_sections') { (new StorySectionAdapter())->apply($translation, $value); continue; }
          $old = $translation->get($field)->getValue();
          foreach ($value as $delta => &$item) {
            if (isset($item['@source_file'])) { $item = (new ManagedFileIdentity($this->destination))->materialize($item); }
            if (isset($old[$delta]) && (!isset($item['target_id']) || (string) $item['target_id'] === (string) ($old[$delta]['target_id'] ?? ''))) { $item += $old[$delta]; }
          }
          unset($item);
          $translation->set($field, $value);
        }
      }
      $violations = $node->validate();
      if ($violations->count()) {
        $paths = [];
        foreach ($violations as $violation) { $paths[] = $violation->getPropertyPath() . ': ' . $violation->getMessage(); }
        throw new \RuntimeException('Mapped node fails validation: ' . implode(', ', array_unique($paths)));
      }
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Explicit incremental source synchronization: ' . $operation);
      $wasNew = $node->isNew();
      $node->save();
      if (\Drupal::hasService('search_api.entity_datasource.tracking_manager')) {
        $node->search_api_skip_tracking = FALSE;
        // Core removes original after save; retain it for deleted languages.
        if ($originalForTracking) { $node->original = $originalForTracking; }
        $tracking = \Drupal::service('search_api.entity_datasource.tracking_manager');
        if ($wasNew) { $tracking->entityInsert($node); }
        else { $tracking->entityUpdate($node); }
        unset($node->original);
      }
      \Drupal::service('labdoo_migrate.tracking.migration_tracker')->track('node', $context['bundle'], $sourceId, (int) $node->id(), 0);
      $storage->resetCache([$node->id()]);
      $saved = $storage->load($node->id());
      return ['destination_id' => (int) $node->id(), 'destination_hash' => $this->hashNode($saved), 'written' => TRUE, 'effects' => ['before' => $relationsBefore, 'after' => $this->relations($saved)]];
    }
    finally {
      $switcher->switchBack();
      if ($prior === $sentinel) { $state->delete('labdoo_migrate_is_running'); }
      else { $state->set('labdoo_migrate_is_running', $prior); }
    }
  }
}
