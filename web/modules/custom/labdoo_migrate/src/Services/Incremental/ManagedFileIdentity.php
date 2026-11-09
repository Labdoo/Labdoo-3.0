<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Database\Connection;

/** Read-only file identity resolution, including already migrated/renamed URIs. */
final class ManagedFileIdentity {
  public function __construct(private Connection $database) {}

  public function resolve(int $sourceId, string $uri): ?int {
    $ids = [];
    if ($this->database->schema()->tableExists('migrate_map_d7_file')) {
      $ids = $this->database->select('migrate_map_d7_file', 'm')->fields('m', ['destid1'])->condition('sourceid1', $sourceId)->execute()->fetchCol();
    }
    if ($this->database->schema()->tableExists('labdoo_migrate_tracking')) {
      $ids = array_merge($ids, $this->database->select('labdoo_migrate_tracking', 't')->fields('t', ['destination_id'])->condition('entity_type', 'file')->condition('source_id', $sourceId)->execute()->fetchCol());
    }
    $ids = array_unique(array_filter(array_map('intval', $ids)));
    if ($ids) {
      if (count($ids) !== 1 || !($entity = ReadOnlyEntityLoader::storage('file')->load(reset($ids)))) { throw new \RuntimeException('Conflicting or dangling managed file identity.'); }
      if (parse_url($uri, PHP_URL_SCHEME) !== parse_url($entity->getFileUri(), PHP_URL_SCHEME)) { throw new \RuntimeException('Managed file identity crosses public/private scheme boundaries.'); }
      return reset($ids);
    }
    $ids = \Drupal::entityTypeManager()->getStorage('file')->getQuery()->accessCheck(FALSE)->condition('uri', $uri)->execute();
    if (count($ids) > 1) { throw new \RuntimeException('Ambiguous managed file URI.'); }
    return $ids ? (int) reset($ids) : NULL;
  }
  /** Virtual references keep planning strictly read-only. */
  public function project(array $file): array {
    $id = $this->resolve((int) $file['fid'], $file['uri']);
    return $id ? ['target_id' => $id] : ['@source_file' => $file];
  }

  /** Only database metadata is created; binary files must be synchronized first. */
  public function materialize(array $item): array {
    if (!isset($item['@source_file'])) { return $item; }
    $file = $item['@source_file'];
    $id = $this->resolve((int) $file['fid'], $file['uri']);
    if (!$id) {
      $uri = $file['uri'];
      if (!str_starts_with($uri, 'public://') || !is_file($uri) || !is_readable($uri)) {
        throw new \RuntimeException('Synchronize source public files before applying node references.');
      }
      $filesystem = \Drupal::service('file_system');
      $root = $filesystem->realpath('public://');
      $path = $filesystem->realpath($uri);
      if (!$root || !$path || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        throw new \RuntimeException('Managed file path escapes the public filesystem.');
      }
      $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
      $sourceMime = strtolower($file['filemime'] ?? 'application/octet-stream');
      if ($sourceMime === 'image/jpg') { $sourceMime = 'image/jpeg'; }
      if (!$mime || ($sourceMime !== 'application/octet-stream' && $sourceMime !== strtolower($mime))) {
        throw new \RuntimeException('Source file MIME does not match synchronized binary.');
      }
      $size = filesize($path);
      if (isset($file['filesize']) && (int) $file['filesize'] !== $size) {
        throw new \RuntimeException('Source binary size does not match synchronized file.');
      }
      $entity = \Drupal::entityTypeManager()->getStorage('file')->create([
        'uri' => $uri, 'filename' => basename($file['filename'] ?? $uri),
        'filemime' => $mime,
        'filesize' => $size, 'status' => 1, 'uid' => 0,
      ]);
      $entity->save();
      $id = (int) $entity->id();
    }
    unset($item['@source_file']);
    return ['target_id' => $id] + $item;
  }

}
