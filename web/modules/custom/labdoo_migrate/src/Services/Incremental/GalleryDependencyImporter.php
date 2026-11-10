<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Database\Connection;
use Drupal\node\NodeInterface;

/** Explicit create-only gallery dependencies; never saves the owner node. */
final class GalleryDependencyImporter {
  private const MAX_PHOTOS = 1000;
  private const LOCK = 'labdoo_migrate.delta';
  private array $graph = [];
  private ?NodeSyncAdapter $ownerAdapter = NULL;

  public function __construct(private Connection $source, private Connection $destination) {}

  private function blocked(string $reason): never { throw new GalleryDependencyBlocked($reason); }

  private function rows($query, int $limit = 1000): array {
    $rows = $query->range(0, $limit + 1)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    if (count($rows) > $limit) { $this->blocked('gallery_dependency_capacity_exceeded'); }
    return $rows;
  }

  private function user(int $sourceId): int {
    if ($sourceId === 0 && ReadOnlyEntityLoader::storage('user')->load(0)) { return 0; }
    $ids = [];
    if ($this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
      $ids = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['destination_id'])->condition('entity_type', 'user')->condition('source_id', $sourceId)->execute()->fetchCol();
    }
    if ($this->destination->schema()->tableExists('migrate_map_d7_user')) {
      $ids = array_merge($ids, $this->destination->select('migrate_map_d7_user', 'm')->fields('m', ['destid1'])->condition('sourceid1', $sourceId)->execute()->fetchCol());
    }
    $ids = array_unique(array_map('intval', $ids));
    if (count($ids) !== 1 || !ReadOnlyEntityLoader::storage('user')->load(reset($ids))) { $this->blocked('gallery_author_identity_unproven'); }
    return reset($ids);
  }

  private function physical(array $file): array {
    if (!str_starts_with($file['uri'], 'public://')) { $this->blocked('gallery_file_not_public'); }
    $relative = substr($file['uri'], 9);
    if (in_array('..', explode('/', $relative), TRUE)) { $this->blocked('gallery_file_path_unsafe'); }
    $filesystem = \Drupal::service('file_system');
    $root = $filesystem->realpath('public://'); $path = $filesystem->realpath($file['uri']);
    if (!$root || !$path || !str_starts_with($path, rtrim($root, '/') . '/') || !is_file($path) || !is_readable($path)) { $this->blocked('gallery_binary_not_synchronized'); }
    $size = filesize($path);
    if ($size > 52428800 || $size !== (int) $file['filesize']) { $this->blocked('gallery_binary_size_mismatch_or_limit'); }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    $expected = strtolower($file['filemime']); if ($expected === 'image/jpg') { $expected = 'image/jpeg'; }
    if (!str_starts_with((string) $mime, 'image/') || ($expected !== 'application/octet-stream' && $mime !== $expected)) { $this->blocked('gallery_binary_mime_mismatch'); }
    return ['bytes' => $size, 'sha256' => hash_file('sha256', $path), 'mime' => $mime];
  }

  private function canonical($value) {
    if (is_array($value)) {
      if (!array_is_list($value)) { ksort($value); }
      foreach ($value as &$item) { $item = $this->canonical($item); }
      return $value;
    }
    return $value === NULL ? NULL : (string) $value;
  }

  private function language(string $source): string {
    $lang = $source && $source !== 'und' ? $source : \Drupal::languageManager()->getDefaultLanguage()->getId();
    if (!isset(\Drupal::languageManager()->getLanguages()[$lang])) { $this->blocked('gallery_source_language_unavailable'); }
    return $lang;
  }

  private function body(array $rows, string $language): array {
    $exact = array_values(array_filter($rows, static fn(array $row): bool => $row['language'] === $language));
    $rows = $exact ?: array_values(array_filter($rows, static fn(array $row): bool => $row['language'] === 'und'));
    if (count($rows) > 1) { $this->blocked('gallery_body_translation_ambiguous'); }
    if (!$rows || (($rows[0]['body_value'] ?? '') === '' && ($rows[0]['body_summary'] ?? '') === '')) { return []; }
    $body = $rows[0];
    $format = match ($body['body_format']) { 'full_html', 'filtered_html_advanced' => 'full_html', 'filtered_html', 'basic_html' => 'basic_html', 'plain_text', 'php_code' => 'plain_text', default => NULL };
    if ($format === NULL) { $this->blocked('gallery_source_text_format_unknown'); }
    return ['value' => $body['body_value'], 'summary' => $body['body_summary'] ?? '', 'format' => $format];
  }

  public function inspect(string $sourceType, int $sourceOwner): array {
    $this->graph = [];
    try {
      if (!in_array($sourceType, ['hub', 'edoovillage'], TRUE)) { return ['dependency' => NULL, 'blocked' => NULL]; }
      $owner = $this->source->select('node', 'n')->fields('n')->condition('nid', $sourceOwner)->execute()->fetchAssoc();
      if (!$owner || $owner['type'] !== $sourceType || !in_array((int) $owner['tnid'], [0, $sourceOwner], TRUE)) { $this->blocked('gallery_source_owner_identity_invalid'); }
      $field = $sourceType === 'hub' ? 'field_hub_photo_album' : 'field_edoovillage_photo_album';
      $family = $this->source->select('node', 'n')->fields('n', ['nid'])->condition('type', $sourceType);
      $family->condition($family->orConditionGroup()->condition('nid', $sourceOwner)->condition('tnid', $sourceOwner));
      $familyIds = array_map('intval', array_column($this->rows($family), 'nid'));
      $refs = $this->rows($this->source->select('field_data_' . $field, 'f')->fields('f')->condition('entity_type', 'node')->condition('bundle', $sourceType)->condition('deleted', 0)->condition('entity_id', $familyIds, 'IN')->orderBy('entity_id')->orderBy('language')->orderBy('delta'));
      $galleryIds = array_values(array_unique(array_filter(array_map('intval', array_column($refs, $field . '_target_id')))));
      if (!$galleryIds) { return ['dependency' => NULL, 'blocked' => NULL]; }
      if (count($galleryIds) !== 1) { $this->blocked('gallery_source_owner_has_multiple_albums'); }
      $gid = $galleryIds[0];
      $resolved = (new GeneratedIdentityResolver($this->source, $this->destination))->galleries([$gid])[$gid];
      $proof = $resolved['proof'];
      if (($proof['source_owner'] ?? NULL) !== $sourceOwner || ($proof['owner_bundle'] ?? NULL) !== $sourceType) { $this->blocked('gallery_source_relation_not_reciprocal'); }
      $gallery = $this->source->select('node', 'n')->fields('n')->condition('nid', $gid)->execute()->fetchAssoc();
      $bodyRows = $this->rows($this->source->select('field_data_body', 'b')->fields('b')->condition('entity_type', 'node')->condition('bundle', 'node_gallery_gallery')->condition('deleted', 0)->condition('entity_id', $gid)->orderBy('language')->orderBy('delta'));
      foreach ($bodyRows as $row) { if ((int) $row['revision_id'] !== (int) $gallery['vid']) { $this->blocked('gallery_source_body_revision_mismatch'); } }
      $relationships = $this->rows($this->source->select('node_gallery_relationship', 'r')->fields('r')->condition('ngid', $gid)->orderBy('weight')->orderBy('id'), self::MAX_PHOTOS);
      $photoIds = array_map('intval', array_column($relationships, 'nid'));
      if (count($photoIds) !== count(array_unique($photoIds))) { $this->blocked('gallery_source_photo_duplicate'); }
      $photos = $files = $physical = $actors = [];
      $actors[(int) $gallery['uid']] = $this->user((int) $gallery['uid']);
      if ($photoIds) {
        $nodes = $this->rows($this->source->select('node', 'n')->fields('n')->condition('nid', $photoIds, 'IN')->orderBy('nid'));
        $mediaRows = $this->rows($this->source->select('field_data_node_gallery_media', 'm')->fields('m')->condition('entity_type', 'node')->condition('bundle', 'node_gallery_item')->condition('deleted', 0)->condition('entity_id', $photoIds, 'IN')->orderBy('entity_id')->orderBy('language')->orderBy('delta'));
        $nodeMap = []; foreach ($nodes as $node) { $nodeMap[(int) $node['nid']] = $node; }
        $media = []; foreach ($mediaRows as $row) {
          $id = (int) $row['entity_id'];
          if (!isset($nodeMap[$id]) || (int) $row['revision_id'] !== (int) $nodeMap[$id]['vid'] || (int) $row['delta'] !== 0 || isset($media[$id])) { $this->blocked('gallery_source_photo_field_ambiguous'); }
          $media[$id] = $row;
        }
        $fids = [];
        foreach ($relationships as $relationship) {
          $id = (int) $relationship['nid']; $node = $nodeMap[$id] ?? NULL; $image = $media[$id] ?? NULL;
          if (!$node || $node['type'] !== 'node_gallery_item' || !$image || empty($image['node_gallery_media_fid'])) { $this->blocked('gallery_source_photo_or_file_missing'); }
          $fid = (int) $image['node_gallery_media_fid']; $fids[$fid] = $fid;
          if (!array_key_exists((int) $node['uid'], $actors)) { $actors[(int) $node['uid']] = $this->user((int) $node['uid']); }
          $photos[] = ['node' => $node, 'image' => $image, 'fid' => $fid, 'alt' => isset($image['node_gallery_media_alt']) ? (string) $image['node_gallery_media_alt'] : (string) $node['title'], 'title' => isset($image['node_gallery_media_title']) ? (string) $image['node_gallery_media_title'] : (string) $node['title']];
        }
        foreach ($this->rows($this->source->select('file_managed', 'f')->fields('f')->condition('fid', array_values($fids), 'IN')->orderBy('fid')) as $file) { $files[(int) $file['fid']] = $file; }
        $bytes = 0;
        foreach ($fids as $fid) {
          if (!isset($files[$fid])) { $this->blocked('gallery_source_file_metadata_missing'); }
          $physical[$fid] = $this->physical($files[$fid]); $bytes += $physical[$fid]['bytes'];
          if ($bytes > 268435456) { $this->blocked('gallery_total_binary_limit'); }
        }
      }
      $data = ['owner_identity' => array_intersect_key($owner, array_flip(['nid', 'vid', 'type', 'tnid'])), 'references' => $refs, 'gallery' => $gallery, 'body_rows' => $bodyRows, 'relationships' => $relationships, 'photos' => $photos, 'files' => $files];
      $data['destination_language'] = $this->language((string) $gallery['language']);
      $data['destination_body'] = $this->body($bodyRows, $data['destination_language']);
      $sourceHash = hash('sha256', serialize($this->canonical($data)));
      $proofHash = hash('sha256', serialize($this->canonical([$data, $physical, $actors])));
      $this->graph = $data + ['physical' => $physical, 'actors' => $actors];
      return ['dependency' => ['kind' => 'gallery', 'source_owner_id' => $sourceOwner, 'source_gallery_id' => $gid, 'owner_bundle' => $sourceType, 'proof_hash' => $proofHash, 'source_hash' => $sourceHash, 'photo_count' => count($photos)], 'blocked' => NULL];
    }
    catch (GalleryDependencyBlocked $e) { return ['dependency' => NULL, 'blocked' => $e->getMessage()]; }
  }

  private function expected(array $dependency, int $destinationOwner): NodeInterface {
    foreach (['source_owner_id', 'source_gallery_id', 'owner_bundle', 'proof_hash', 'source_hash', 'photo_count', 'destination_owner_uuid'] as $key) { if (!isset($dependency[$key])) { throw new \RuntimeException('Incomplete gallery dependency evidence: ' . $key); } }
    $fresh = $this->inspect($dependency['owner_bundle'], (int) $dependency['source_owner_id']);
    if (!$fresh['dependency'] || $fresh['blocked']) { throw new \RuntimeException($fresh['blocked'] ?? 'Gallery source proof missing.'); }
    foreach (['source_gallery_id', 'proof_hash', 'source_hash', 'photo_count'] as $key) {
      if ((string) $fresh['dependency'][$key] !== (string) $dependency[$key]) { throw new \RuntimeException('Gallery source or assets changed after planning.'); }
    }
    $node = ReadOnlyEntityLoader::storage('node')->load($destinationOwner);
    if (!$node || $node->bundle() !== $dependency['owner_bundle'] || $node->uuid() !== $dependency['destination_owner_uuid']) { throw new \RuntimeException('Gallery owner UUID/bundle changed.'); }
    $matches = [];
    if ($this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
      $matches = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['destination_id'])->condition('entity_type', 'node')->condition('bundle', $node->bundle())->condition('source_id', (int) $dependency['source_owner_id'])->execute()->fetchCol();
    }
    if ($node->hasField('field_d7_nid') && (int) $node->get('field_d7_nid')->value === (int) $dependency['source_owner_id']) { $matches[] = $destinationOwner; }
    $matches = array_unique(array_map('intval', $matches));
    if ($matches !== [$destinationOwner]) { throw new \RuntimeException('Gallery destination owner migration identity is unproven.'); }
    return $node;
  }

  private function gallery(array $dependency, int $owner): ?NodeInterface {
    $ids = [];
    if ($this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
      $ids = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['destination_id'])->condition('entity_type', 'node')->condition('bundle', 'gallery')->condition('source_id', (int) $dependency['source_gallery_id'])->execute()->fetchCol();
    }
    foreach ($this->destination->select('node__field_parent', 'p')->fields('p', ['entity_id'])->condition('bundle', 'gallery')->condition('deleted', 0)->condition('field_parent_target_id', $owner)->execute()->fetchCol() as $id) { $ids[] = $id; }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) > 1) { throw new \RuntimeException('Ambiguous destination gallery.'); }
    if (!$ids) { return NULL; }
    $node = ReadOnlyEntityLoader::storage('node')->load($ids[0]);
    if (!$node || $node->bundle() !== 'gallery' || !$node->hasField('field_parent') || !$node->hasField('field_images')) { throw new \RuntimeException('Gallery destination type or schema mismatch.'); }
    foreach ($node->getTranslationLanguages() as $lang => $language) {
      $parents = array_unique(array_map('intval', array_column($node->getTranslation($lang)->get('field_parent')->getValue(), 'target_id')));
      if ($parents !== [$owner]) { throw new \RuntimeException('Existing gallery belongs to another owner.'); }
    }
    return $node;
  }

  private function mediaValues(array $photo, int $fileId): array {
    return ['fid' => $fileId, 'alt' => $photo['alt'], 'title' => $photo['title'], 'uid' => $this->graph['actors'][(int) $photo['node']['uid']], 'status' => (int) $photo['node']['status']];
  }

  private function matches($media, array $expected): bool {
    if (!$media || $media->bundle() !== 'image' || !$media->hasField('field_media_image')) { return FALSE; }
    $items = $media->get('field_media_image')->getValue();
    return count($items) === 1 && (int) $items[0]['target_id'] === $expected['fid'] && (string) ($items[0]['alt'] ?? '') === $expected['alt'] && (string) ($items[0]['title'] ?? '') === $expected['title'] && (int) $media->getOwnerId() === $expected['uid'] && (int) $media->isPublished() === $expected['status'];
  }

  private function mediaId(array $photo, array $expected): ?int {
    $ids = [];
    if ($this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
      $ids = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['destination_id'])->condition('entity_type', 'media')->condition('bundle', 'image')->condition('source_id', (int) $photo['node']['nid'])->execute()->fetchCol();
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) > 1) { throw new \RuntimeException('Ambiguous source photo media identity.'); }
    if ($ids) {
      if (!$this->matches(ReadOnlyEntityLoader::storage('media')->load($ids[0]), $expected)) { throw new \RuntimeException('Tracked photo was changed locally.'); }
      return $ids[0];
    }
    $query = $this->destination->select('media__field_media_image', 'i')->fields('i', ['entity_id'])->condition('i.deleted', 0)->condition('i.field_media_image_target_id', $expected['fid'])->condition('i.field_media_image_alt', $expected['alt'])->condition('i.field_media_image_title', $expected['title']);
    $candidates = array_unique(array_map('intval', $query->range(0, 1001)->execute()->fetchCol()));
    if (count($candidates) > 1000) { throw new \RuntimeException('Photo candidates exceed capacity.'); }
    $matches = [];
    foreach (ReadOnlyEntityLoader::storage('media')->loadMultiple($candidates) as $id => $media) { if ($this->matches($media, $expected)) { $matches[] = (int) $id; } }
    if (count($matches) > 1) { throw new \RuntimeException('Ambiguous file/caption media identity.'); }
    return $matches[0] ?? NULL;
  }

  private function photoIds(bool $create): array {
    $result = []; $fileIdentity = new ManagedFileIdentity($this->destination);
    foreach ($this->graph['photos'] as $photo) {
      if ($create && !\Drupal::lock()->acquire(self::LOCK, 300)) { throw new \RuntimeException('Gallery import lease lost.'); }
      $file = $this->graph['files'][$photo['fid']];
      $projected = $fileIdentity->project($file);
      if (isset($projected['@source_file'])) {
        if (!$create) { throw new \RuntimeException('Gallery file metadata has not been imported.'); }
        $projected = $fileIdentity->materialize($projected);
      }
      $fileId = (int) $projected['target_id'];
      $targetFile = ReadOnlyEntityLoader::storage('file')->load($fileId);
      if (!$targetFile) { throw new \RuntimeException('Gallery target file identity missing.'); }
      $targetPhysical = $this->physical(['uri' => $targetFile->getFileUri(), 'filesize' => $file['filesize'], 'filemime' => $file['filemime']]);
      if ($targetPhysical['sha256'] !== $this->graph['physical'][$photo['fid']]['sha256']) { throw new \RuntimeException('Mapped photo binary differs from source public asset.'); }
      $expected = $this->mediaValues($photo, $fileId); $id = $this->mediaId($photo, $expected);
      if (!$id) {
        if (!$create) { throw new \RuntimeException('Gallery photo media has not been imported.'); }
        $media = \Drupal::entityTypeManager()->getStorage('media')->create(['bundle' => 'image', 'langcode' => $this->language((string) $photo['node']['language']), 'name' => $photo['node']['title'], 'uid' => $expected['uid'], 'status' => $expected['status'], 'created' => (int) $photo['node']['created'], 'changed' => (int) $photo['node']['changed'], 'field_media_image' => ['target_id' => $fileId, 'alt' => $photo['alt'], 'title' => $photo['title']]]);
        $media->setSyncing(TRUE); $media->search_api_skip_tracking = TRUE;
        if ($media->validate()->count()) { throw new \RuntimeException('Source photo fails destination media validation.'); }
        $media->save(); $this->trackCreated($media); $id = (int) $media->id();
      }
      if ($create) { \Drupal::service('labdoo_migrate.tracking.migration_tracker')->track('media', 'image', (int) $photo['node']['nid'], $id, 0); }
      $result[] = $id;
    }
    return $result;
  }

  public function verify(array $dependency, int $destinationOwner): array {
    $this->expected($dependency, $destinationOwner);
    $gallery = $this->gallery($dependency, $destinationOwner);
    if (!$gallery) { throw new \RuntimeException('Gallery dependency has not been imported.'); }
    $expected = $this->photoIds(FALSE);
    foreach ($gallery->getTranslationLanguages() as $lang => $language) {
      $actual = array_map('intval', array_column($gallery->getTranslation($lang)->get('field_images')->getValue(), 'target_id'));
      if ($actual !== $expected) { throw new \RuntimeException('Gallery photos differ, are missing or contain local extras.'); }
    }
    return ['destination_gallery_id' => (int) $gallery->id(), 'verified_photo_count' => count($expected), 'proof_hash' => $dependency['proof_hash']];
  }

  /** Tracks only this newly created entity, using configured index datasources. */
  private function trackCreated($entity): void {
    if (\Drupal::hasService('search_api.entity_datasource.tracking_manager')) {
      $entity->search_api_skip_tracking = FALSE;
      \Drupal::service('search_api.entity_datasource.tracking_manager')->entityInsert($entity);
    }
  }

  private function ownerHash(string $bundle, int $id): string {
    $this->ownerAdapter ??= new NodeSyncAdapter($this->source, $this->destination);
    return $this->ownerAdapter->hashes($bundle, [$id])[$id] ?? throw new \RuntimeException('Gallery owner hash could not be read.');
  }

  private function safeAutoNodeRange(): void {
    $sourceMaximum = (int) $this->source->query('SELECT MAX(nid) FROM {node}')->fetchField();
    $table = trim($this->destination->prefixTables('{node}'), '`"');
    $next = $this->destination->query('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=:schema AND TABLE_NAME=:table', [':schema' => $this->destination->getConnectionOptions()['database'], ':table' => $table])->fetchField();
    if (!$next || (int) $next <= $sourceMaximum) {
      throw new \RuntimeException('Gallery auto-ID range overlaps source node IDs; explicit operator preparation is required.');
    }
  }

  public function import(array $dependency, int $destinationOwner): array {
    if ($this->destination !== \Drupal::database()) { throw new \RuntimeException('Gallery importer requires the local destination connection.'); }
    $lock = \Drupal::lock();
    if (!$lock->acquire(self::LOCK, 300)) { throw new \RuntimeException('Another delta operation is active.'); }
    $state = \Drupal::state(); $sentinel = new \stdClass(); $prior = $state->get('labdoo_migrate_is_running', $sentinel); $stateChanged = FALSE; $switched = FALSE; $switcher = \Drupal::service('account_switcher');
    $transaction = $this->destination->startTransaction();
    try {
      if ($prior !== $sentinel && (bool) $prior) { throw new \RuntimeException('A legacy migration is active; gallery import cannot interleave.'); }
      if (empty($dependency['destination_owner_hash'])) { throw new \RuntimeException('Gallery import requires the original staged owner hash.'); }
      $guard = $this->destination->select('node', 'n')->fields('n', ['nid', 'type', 'vid'])->condition('nid', $destinationOwner)->forUpdate()->execute()->fetchAssoc();
      if (!$guard) { throw new \RuntimeException('Staged gallery owner disappeared.'); }
      $parent = $this->expected($dependency, $destinationOwner);
      $ownerBefore = $this->ownerHash($parent->bundle(), $destinationOwner);
      if (!hash_equals($dependency['destination_owner_hash'], $ownerBefore)) { throw new \RuntimeException('Staged gallery owner was edited after planning.'); }
      $existing = $this->gallery($dependency, $destinationOwner);
      if ($existing) { $verified = $this->verify($dependency, $destinationOwner); unset($transaction); return $verified; }
      $this->safeAutoNodeRange();
      $administrator = ReadOnlyEntityLoader::storage('user')->load(1);
      if (!$administrator) { throw new \RuntimeException('A trusted migration administrator (UID1) is required.'); }
      $switcher->switchTo($administrator); $switched = TRUE;
      $state->set('labdoo_migrate_is_running', TRUE); $stateChanged = TRUE;
      $photos = $this->photoIds(TRUE);
      $source = $this->graph['gallery'];
      $values = ['type' => 'gallery', 'title' => $source['title'], 'uid' => $this->graph['actors'][(int) $source['uid']], 'status' => (int) $source['status'], 'langcode' => $this->graph['destination_language'], 'created' => (int) $source['created'], 'changed' => (int) $source['changed'], 'field_parent' => $destinationOwner, 'field_images' => $photos];
      if ($this->graph['destination_body']) { $values['body'] = $this->graph['destination_body']; }
      $gallery = \Drupal::entityTypeManager()->getStorage('node')->create($values);
      foreach (['skip_action_generation', 'skip_recompute_enqueue', 'skip_geocoding_enqueue', 'skip_gallery_generation', 'search_api_skip_tracking', 'labdoo_skip_geocoding'] as $flag) { $gallery->$flag = TRUE; }
      $gallery->original_entity_id = (int) $source['nid']; $gallery->setSyncing(TRUE);
      if ($gallery->validate()->count()) { throw new \RuntimeException('Source gallery fails destination validation.'); }
      $gallery->save();
      $this->trackCreated($gallery);
      \Drupal::service('labdoo_migrate.tracking.migration_tracker')->track('node', 'gallery', (int) $source['nid'], (int) $gallery->id(), 0);
      $result = $this->verify($dependency, $destinationOwner);
      if (!hash_equals($ownerBefore, $this->ownerHash($parent->bundle(), $destinationOwner))) { throw new \RuntimeException('Gallery import changed the staged owner.'); }
      if ($prior === $sentinel) { $state->delete('labdoo_migrate_is_running'); } else { $state->set('labdoo_migrate_is_running', $prior); }
      $stateChanged = FALSE;
      unset($transaction);
      return $result;
    }
    catch (\Throwable $e) { $transaction->rollBack(); \Drupal::service('entity.memory_cache')->deleteAll(); throw $e; }
    finally {
      if ($switched) { $switcher->switchBack(); }
      if ($stateChanged) { if ($prior === $sentinel) { $state->delete('labdoo_migrate_is_running'); } else { $state->set('labdoo_migrate_is_running', $prior); } }
      $lock->release(self::LOCK);
    }
  }
}

/** Expected preflight blocker; database/infrastructure failures are not hidden. */
final class GalleryDependencyBlocked extends \RuntimeException {}
