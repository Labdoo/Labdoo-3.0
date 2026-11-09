<?php

/**
 * @file
 * Preserves old public URLs after a verified file-URI metadata repair.
 *
 * A separate filesystem phase: it does not claim transactional filesystem I/O.
 */

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;

require_once __DIR__ . '/public-file-uri-parity.php';

final class LabdooPublicFileUriAliases {
  private const PLACEHOLDER = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

  public function __construct(private Connection $database, private string $root) {
    $this->root = realpath($root) ?: throw new RuntimeException('Public root is unavailable.');
  }

  private static function relative(string $uri): string {
    if (!str_starts_with($uri, 'public://')) {
      throw new RuntimeException('Only public stream URIs may receive aliases.');
    }
    $parts = [];
    foreach (explode('/', substr($uri, 9)) as $part) {
      if ($part === '..' || str_contains($part, "\0") || str_contains($part, '\\')) {
        throw new RuntimeException('Unsafe public path.');
      }
      if ($part !== '' && $part !== '.') {
        $parts[] = $part;
      }
    }
    if (!$parts || in_array(strtolower($parts[0]), ['private', 'php', 'css', 'js', 'styles'], TRUE) || preg_match('/\.(php\d*|phtml|phar|htaccess)$/i', end($parts))) {
      throw new RuntimeException('Excluded public path.');
    }
    return implode('/', $parts);
  }

  private function contained(string $path): bool {
    if (!str_starts_with($path, $this->root . '/')) {
      return FALSE;
    }
    try {
      self::relative('public://' . substr($path, strlen($this->root) + 1));
      return TRUE;
    }
    catch (RuntimeException) {
      return FALSE;
    }
  }

  private function parent(string $relative, bool $create = FALSE): string {
    $current = $this->root;
    $parts = explode('/', dirname($relative));
    foreach ($parts as $part) {
      if ($part === '.') {
        continue;
      }
      $next = $current . '/' . $part;
      if (is_link($next) || file_exists($next)) {
        $next = realpath($next);
        if (!$next || !$this->contained($next) || !is_dir($next)) {
          throw new RuntimeException('Public path parent escapes the public root.');
        }
      }
      elseif ($create) {
        if (!mkdir($next, 0775)) {
          throw new RuntimeException('Cannot create public alias directory.');
        }
        // Keep backups private with umask0077, but make only newly created
        // public directories traversable/writable by the site's public group.
        if (!chgrp($next, filegroup($this->root)) || !chmod($next, 02775)) {
          throw new RuntimeException('Cannot set the public group/mode on a new alias directory.');
        }
      }
      $current = $next;
    }
    return $current;
  }

  private static function linkTarget(string $parent, string $target): string {
    $from = explode('/', trim($parent, '/'));
    $to = explode('/', trim($target, '/'));
    while ($from && $to && $from[0] === $to[0]) {
      array_shift($from);
      array_shift($to);
    }
    return str_repeat('../', count($from)) . implode('/', $to);
  }

  private static function placeholder(string $path): bool {
    return is_file($path) && !is_link($path) && filesize($path) === 68 && hash_file('sha256', $path) === hash('sha256', base64_decode(self::PLACEHOLDER));
  }

  private static function durable($handle, array $value): void {
    $line = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (fwrite($handle, $line) !== strlen($line) || !fflush($handle) || !fsync($handle)) {
      throw new RuntimeException('Alias backup could not be durably written.');
    }
  }

  private function validateMetadata(array $record): bool {
    $current = $this->database->select('file_managed', 'f')->fields('f', ['uri', 'uuid', 'status'])->condition('fid', $record['file']['fid'])->execute()->fetchAssoc();
    return $current && $current['uri'] === $record['after']['uri'] && $current['uuid'] === $record['file']['uuid'] && (int) $current['status'] === (int) $record['file']['status'];
  }

  public function plan(string $metadataBackup): array {
    $header = NULL;
    $records = [];
    $counts = ['scanned' => 0, 'repairable' => 0, 'already_alias' => 0, 'protected_existing' => 0, 'metadata_changed' => 0, 'unsafe_or_changed_target' => 0];
    foreach (file($metadataBackup, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $entry = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
      if ($entry['kind'] === 'header') {
        $header = $entry;
        if (($header['schema'] ?? NULL) !== 1 || $header['database'] !== $this->database->getConnectionOptions()['database']) {
          throw new RuntimeException('Metadata backup schema/database mismatch.');
        }
      }
      if ($entry['kind'] !== 'file') {
        continue;
      }
      if (!$header) {
        throw new RuntimeException('Missing metadata backup header.');
      }
      $record = $entry['record'];
      $counts['scanned']++;
      if (!$this->validateMetadata($record)) {
        $counts['metadata_changed']++;
        continue;
      }
      try {
        $old = self::relative($record['file']['uri']);
        $canonical = self::relative($record['proof']['canonical_uri'] ?? $record['after']['uri']);
        $mode = !empty($record['proof']['requires_independent_entry']) ? 'hardlink' : 'symlink';
        $target = realpath($this->root . '/' . $canonical);
        $parent = $this->parent($old);
        if (!$target || !$this->contained($target) || !is_file($target) || filesize($target) !== $record['proof']['actual_bytes'] || filemtime($target) !== $record['proof']['physical_mtime']) {
          throw new RuntimeException('Original changed after metadata repair.');
        }
        $path = $parent . '/' . basename($old);
        if ($path === $target) {
          throw new RuntimeException('Alias and original resolve to the same path.');
        }
        $link = self::linkTarget($parent, $target);
        if (is_link($path)) {
          if ($mode === 'symlink' && readlink($path) === $link && realpath($path) === $target) {
            $counts['already_alias']++;
          }
          else {
            $counts['protected_existing']++;
          }
          continue;
        }
        $before = ['kind' => 'missing'];
        if ($mode === 'hardlink' && is_file($path) && !is_link($path)) {
          $oldStat = lstat($path);
          $targetStat = lstat($target);
          if ($oldStat['dev'] === $targetStat['dev'] && $oldStat['ino'] === $targetStat['ino'] && $oldStat['nlink'] >= 2) {
            $counts['already_alias']++;
            continue;
          }
        }
        if (file_exists($path)) {
          if (empty($record['proof']['placeholder_metadata']) || !self::placeholder($path)) {
            $counts['protected_existing']++;
            continue;
          }
          $stat = lstat($path);
          $before = ['kind' => 'placeholder', 'base64' => self::PLACEHOLDER, 'stat' => array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'mtime']))];
        }
        $records[] = ['operation' => bin2hex(random_bytes(12)), 'fid' => (int) $record['file']['fid'], 'uuid' => $record['file']['uuid'], 'status' => $record['file']['status'], 'old_uri' => $record['file']['uri'], 'expected_uri' => $record['after']['uri'], 'canonical_uri' => $record['proof']['canonical_uri'] ?? $record['after']['uri'], 'mode' => $mode, 'old_relative' => $old, 'canonical_relative' => $canonical, 'parent' => $parent, 'target' => $target, 'link_target' => $link, 'before' => $before, 'target_bytes' => $record['proof']['actual_bytes'], 'target_mtime' => $record['proof']['physical_mtime']];
        $counts['repairable']++;
      }
      catch (RuntimeException) {
        $counts['unsafe_or_changed_target']++;
      }
    }
    if (!$header) {
      throw new RuntimeException('Missing metadata backup header.');
    }
    return ['schema' => 1, 'root' => $this->root, 'summary' => $counts, 'records' => $records];
  }

  public function apply(array $plan, string $backupPath): array {
    $backup = LabdooPublicFileUriParity::privateOutput($backupPath);
    $created = 0;
    try {
      self::durable($backup, ['kind' => 'alias_header', 'schema' => 1, 'database' => $this->database->getConnectionOptions()['database'], 'root' => $this->root, 'captured_at' => gmdate('c')]);
      foreach ($plan['records'] as $record) {
        if (!$this->validateMetadata(['file' => ['fid' => $record['fid'], 'uuid' => $record['uuid'], 'status' => $record['status']], 'after' => ['uri' => $record['expected_uri'] ?? $record['canonical_uri']]])) {
          throw new RuntimeException('File metadata changed before alias creation.');
        }
        clearstatcache();
        $target = realpath($this->root . '/' . self::relative($record['canonical_uri']));
        if ($target !== $record['target'] || !$this->contained($target) || filesize($target) !== $record['target_bytes'] || filemtime($target) !== $record['target_mtime']) {
          throw new RuntimeException('Original changed before alias creation.');
        }
        $record['hold_relative'] = dirname($record['old_relative']) . '/.labdoo-placeholder-' . $record['operation'];
        self::durable($backup, ['kind' => 'alias_intent', 'record' => $record]);
        $parent = $this->parent($record['old_relative'], TRUE);
        if ($parent !== $record['parent']) {
          throw new RuntimeException('Alias parent changed after the plan.');
        }
        $path = $parent . '/' . basename($record['old_relative']);
        $hold = $parent . '/.labdoo-placeholder-' . $record['operation'];
        if ($record['before']['kind'] === 'placeholder') {
          $stat = lstat($path);
          if (!self::placeholder($path) || $stat['dev'] !== $record['before']['stat']['dev'] || $stat['ino'] !== $record['before']['stat']['ino'] || file_exists($hold) || is_link($hold)) {
            throw new RuntimeException('Placeholder changed before alias creation.');
          }
          if (!rename($path, $hold)) {
            throw new RuntimeException('Cannot preserve the placeholder before replacement.');
          }
          if (!self::placeholder($hold) || lstat($hold)['ino'] !== $stat['ino']) {
            if (!file_exists($path) && !is_link($path) && link($hold, $path)) {
              unlink($hold);
            }
            throw new RuntimeException('Concurrent file replacement was preserved; no alias was created.');
          }
        }
        // Both link() and symlink() are no-clobber: never overwrite a path.
        $linked = $record['mode'] === 'hardlink' ? @link($target, $path) : @symlink($record['link_target'], $path);
        if (!$linked) {
          if ($record['before']['kind'] === 'placeholder' && !file_exists($path) && !is_link($path) && link($hold, $path)) {
            unlink($hold);
          }
          throw new RuntimeException('Cannot create alias without overwriting an existing path.');
        }
        $after = lstat($path);
        self::durable($backup, ['kind' => 'alias_applied', 'operation' => $record['operation'], 'dev' => $after['dev'], 'ino' => $after['ino'], 'ctime' => $after['ctime'], 'nlink' => $after['nlink'], 'entry_type' => $record['mode']]);
        if ($record['before']['kind'] === 'placeholder' && self::placeholder($hold)) {
          unlink($hold);
        }
        $created++;
      }
      self::durable($backup, ['kind' => 'alias_complete', 'created' => $created]);
    }
    finally {
      fclose($backup);
    }
    return ['created' => $created];
  }

  public function rollback(string $backupPath): array {
    $records = [];
    $applied = [];
    $header = NULL;
    foreach (file($backupPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $entry = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
      if ($entry['kind'] === 'alias_header') {
        $header = $entry;
      }
      elseif ($entry['kind'] === 'alias_intent') {
        $records[] = $entry['record'];
      }
      elseif ($entry['kind'] === 'alias_applied') {
        $applied[$entry['operation']] = $entry;
      }
    }
    if (!$header || $header['schema'] !== 1 || $header['root'] !== $this->root || $header['database'] !== $this->database->getConnectionOptions()['database']) {
      throw new RuntimeException('Alias backup schema/root/database mismatch.');
    }
    $restored = 0;
    $conflicts = 0;
    foreach (array_reverse($records) as $record) {
      $relative = self::relative($record['old_uri']);
      $parent = $this->parent($relative);
      $path = $parent . '/' . basename($relative);
      $after = $applied[$record['operation']] ?? NULL;
      clearstatcache(TRUE, $path);
      if (!preg_match('/^[a-f0-9]{24}$/', $record['operation'])) {
        throw new RuntimeException('Invalid alias operation identifier.');
      }
      // Recover a crash after moving a placeholder but before making the link.
      $hold = $parent . '/.labdoo-placeholder-' . $record['operation'];
      if (!$after && $parent === $record['parent'] && $record['before']['kind'] === 'placeholder' && !file_exists($path) && !is_link($path) && self::placeholder($hold)) {
        $held = lstat($hold);
        if ($held['dev'] === $record['before']['stat']['dev'] && $held['ino'] === $record['before']['stat']['ino'] && link($hold, $path)) {
          unlink($hold);
          $restored++;
          continue;
        }
      }
      if (!$after || $parent !== $record['parent'] || (!file_exists($path) && !is_link($path))) {
        $conflicts++;
        continue;
      }
      $stat = lstat($path);
      if (($record['mode'] ?? 'symlink') === 'hardlink') {
        $canonical = realpath($this->root . '/' . self::relative($record['canonical_uri']));
        $targetStat = $canonical && $this->contained($canonical) ? lstat($canonical) : FALSE;
        // Never unlink the last surviving copy or a path whose canonical
        // owner has since replaced/deleted its entry. Reverse-order rollback
        // restores nlink counts even when this run created several links.
        $owned = !is_link($path) && $targetStat && $stat['dev'] === $after['dev'] && $stat['ino'] === $after['ino'] && $stat['nlink'] === $after['nlink'] && $stat['nlink'] >= 2 && $targetStat['dev'] === $stat['dev'] && $targetStat['ino'] === $stat['ino'];
      }
      else {
        $owned = is_link($path) && $stat['dev'] === $after['dev'] && $stat['ino'] === $after['ino'] && $stat['ctime'] === $after['ctime'] && readlink($path) === $record['link_target'];
      }
      if (!$owned) {
        $conflicts++;
        continue;
      }
      if (!unlink($path)) {
        throw new RuntimeException('Cannot remove the verified alias.');
      }
      if ($record['before']['kind'] === 'placeholder') {
        $output = fopen($path, 'x');
        if (!$output) {
          throw new RuntimeException('A concurrent file prevents placeholder restoration.');
        }
        $bytes = base64_decode($record['before']['base64'], TRUE);
        if ($bytes !== base64_decode(self::PLACEHOLDER) || fwrite($output, $bytes) !== 68 || !fflush($output) || !fsync($output)) {
          fclose($output);
          throw new RuntimeException('Cannot restore the placeholder before-image.');
        }
        fclose($output);
        chmod($path, $record['before']['stat']['mode'] & 0777);
        touch($path, $record['before']['stat']['mtime']);
        $stat = lstat($path);
        if ($stat['uid'] !== $record['before']['stat']['uid'] && !chown($path, $record['before']['stat']['uid'])) {
          throw new RuntimeException('Placeholder owner could not be restored.');
        }
        if ($stat['gid'] !== $record['before']['stat']['gid'] && !chgrp($path, $record['before']['stat']['gid'])) {
          throw new RuntimeException('Placeholder group could not be restored.');
        }
      }
      $hold = $parent . '/.labdoo-placeholder-' . $record['operation'];
      if (self::placeholder($hold)) {
        unlink($hold);
      }
      $restored++;
    }
    return ['restored' => $restored, 'concurrent_or_unconfirmed_conflicts' => $conflicts];
  }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  umask(0077);
  $options = getopt('', ['root:', 'uri:', 'from:', 'apply', 'backup-path:', 'rollback:', 'help']);
  if (isset($options['help'])) {
    echo "Dry-run: php scripts/public-file-uri-aliases.php --root=/path/web --from=/private/metadata-backup.jsonl\nApply: add --apply --backup-path=/private/new-alias-backup.jsonl\nRollback aliases first: --rollback=/private/alias-backup.jsonl --apply\n";
    exit(0);
  }
  $root = realpath($options['root'] ?? dirname(__DIR__) . '/web');
  if (!$root || !is_file($root . '/autoload.php')) {
    throw new RuntimeException('Use --root to select the installed Drupal web root.');
  }
  // Match Drush's working directory before resolving public:// paths.
  foreach (['from', 'backup-path', 'rollback'] as $pathOption) {
    if (isset($options[$pathOption]) && !str_starts_with($options[$pathOption], '/')) {
      $options[$pathOption] = getcwd() . '/' . $options[$pathOption];
    }
  }
  if (!chdir($root)) {
    throw new RuntimeException('Cannot enter the installed Drupal web root.');
  }
  $loader = require $root . '/autoload.php';
  $request = \Symfony\Component\HttpFoundation\Request::create($options['uri'] ?? 'https://v3.labdoo.org');
  \Drupal\Core\DrupalKernel::createFromRequest($request, $loader, 'prod', FALSE, $root)->boot();
  $database = Database::getConnection('default', 'default');
  $runner = new LabdooPublicFileUriAliases($database, \Drupal::service('file_system')->realpath('public://'));
  if (isset($options['rollback'])) {
    if (!isset($options['apply'])) {
      throw new RuntimeException('Alias rollback requires explicit --apply.');
    }
    echo json_encode($runner->rollback($options['rollback']), JSON_PRETTY_PRINT) . PHP_EOL;
  }
  else {
    if (empty($options['from'])) {
      throw new RuntimeException('Use --from with the verified metadata backup.');
    }
    $plan = $runner->plan($options['from']);
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
