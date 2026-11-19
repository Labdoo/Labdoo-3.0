<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\locale\Gettext;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Durable, owner-scoped translation jobs with checkpointed queue work. */
final class TranslationJobs {

  public const QUEUE = 'labdoo_translation_jobs';
  public const PERMISSION = 'administer labdoo translations';
  public const BATCH = 10;
  private KeyValueStoreInterface $store;

  public function __construct(
    KeyValueFactoryInterface $keyValue,
    private QueueFactory $queues,
    private LockBackendInterface $lock,
    private UuidInterface $uuid,
    private FileSystemInterface $files,
    private TranslationWorkflow $workflow,
    private TranslationAgentRegistry $agents,
    private EntityTypeManagerInterface $entities,
    private ModuleHandlerInterface $modules,
    private LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->store = $keyValue->get('labdoo.translation.jobs');
  }

  /** Returns translated labels rather than translating dynamic state input. */
  public function label(string $state): TranslatableMarkup {
    return match ($state) {
      'queued' => new TranslatableMarkup('Waiting for worker'),
      'running' => new TranslatableMarkup('Processing'),
      'prepared' => new TranslatableMarkup('Input ready'),
      'review' => new TranslatableMarkup('Ready for review'),
      'failed' => new TranslatableMarkup('Failed'),
      'empty' => new TranslatableMarkup('No pending entries'),
      'importing' => new TranslatableMarkup('Importing'),
      'imported' => new TranslatableMarkup('Imported'),
      default => new TranslatableMarkup('Unknown status'),
    };
  }

  /** Gives operators actionable errors without exposing agent diagnostics. */
  public function failureMessage(array $job): TranslatableMarkup {
    return match ($job['error_code'] ?? '') {
      'artifact' => new TranslatableMarkup('A private translation file is missing or has changed. Restore the original file or create a new job. Completed batches are preserved.'),
      'owner' => new TranslatableMarkup('The requesting operator is no longer authorized. Restore the required permission before retrying. Completed batches are preserved.'),
      'configuration' => new TranslatableMarkup('An agent, model or language is no longer available. Check the server configuration before retrying. Completed batches are preserved.'),
      'response' => new TranslatableMarkup('The agent returned an invalid translation response. Check the selected agent and model before retrying. Completed batches are preserved.'),
      'timeout' => new TranslatableMarkup('The agent exceeded the time limit. Retry the unfinished batch when the agent is available. Completed batches are preserved.'),
      'process' => new TranslatableMarkup('The agent did not complete successfully. Check its installation and authentication before retrying. Completed batches are preserved.'),
      default => $job['phase'] === 'import'
        ? new TranslatableMarkup('Import could not finish. Check the result files and retry. Completed batches are preserved.')
        : new TranslatableMarkup('The worker could not finish the translation. Check the agent configuration and private storage, then retry. Completed batches are preserved.'),
    };
  }

  /** Returns the requesting operator's recent jobs without scanning all jobs. */
  public function recent(AccountInterface $account): array {
    $result = [];
    foreach ($this->store->get('owner:' . $account->id(), []) as $id) {
      if ($job = $this->store->get($id)) { $result[] = $job; }
    }
    return $result;
  }

  /** Enforces both the dedicated permission and ownership on every endpoint. */
  public function load(string $id, AccountInterface $account): array {
    $job = $this->record($id);
    if (!$job) { throw new NotFoundHttpException(); }
    if (!$account->isAuthenticated() || !$account->hasPermission(self::PERMISSION) || ((int) $job['uid'] !== (int) $account->id() && (int) $account->id() !== 1)) {
      throw new AccessDeniedHttpException();
    }
    return $job;
  }

  /** Deduplicates a submitted form token under an owner-wide lock. */
  public function create(AccountInterface $account, array $languages, string $agent, string $model, string $request): string {
    if (!$account->isAuthenticated() || !$account->hasPermission(self::PERMISSION)) { throw new AccessDeniedHttpException(); }
    $this->agents->options($agent, $model);
    $allowed = $this->workflow->languages($languages);
    if (!$languages || count($allowed) !== count(array_unique($languages)) || !preg_match('/^[a-f0-9-]{36}$/D', $request)) {
      throw new \InvalidArgumentException('Select target languages and use a valid form.');
    }
    $key = 'create:' . $account->id();
    if (!$this->lock->acquire($key, 30)) { throw new \RuntimeException('A translation request is already being created.'); }
    try {
      $dedup = 'request:' . hash('sha256', $account->id() . ':' . $request);
      if ($id = $this->store->get($dedup)) { return $id; }
      $id = $this->uuid->generate();
      $this->directory($id, TRUE);
      $job = [
        'id' => $id, 'uid' => (int) $account->id(), 'agent' => $agent, 'model' => $model,
        'agent_label' => $this->agents->profiles()[$agent]['label'] ?? $agent,
        'languages' => array_fill_keys(array_keys($allowed), ['total' => 0, 'completed' => 0, 'state' => 'queued', 'parts' => []]),
        'state' => 'queued', 'phase' => 'prepare', 'index' => 0, 'offset' => 0,
        'created' => time(), 'updated' => time(), 'error' => FALSE,
      ];
      $this->save($job);
      $this->store->set($dedup, $id);
      $recent = $this->store->get('owner:' . $account->id(), []);
      array_unshift($recent, $id);
      $this->store->set('owner:' . $account->id(), array_slice(array_unique($recent), 0, 20));
      try { $this->enqueue($id); }
      catch (\Throwable $e) { $job['state'] = 'failed'; $job['error'] = TRUE; $this->save($job); }
      return $id;
    }
    finally { $this->lock->release($key); }
  }

  /** Bounded processing: one preparation, at most one agent call, or one import. */
  public function process(string $id): void {
    if (!$this->record($id)) { return; }
    $key = 'labdoo.translation:' . $id;
    if (!$this->lock->acquire($key, 300)) { throw new DelayedRequeueException(10); }
    try {
      $job = $this->record($id);
      if (!$job || !in_array($job['state'], ['queued', 'running', 'importing'], TRUE)) { return; }
      // A long-running worker must observe account changes made by other
      // requests rather than reuse a previously loaded owner from memory.
      $owner = $this->entities->getStorage('user')->loadUnchanged($job['uid']);
      if (!$owner || !$owner->isActive() || !$owner->hasPermission(self::PERMISSION)) {
        throw new \RuntimeException('The requesting operator is no longer authorized.');
      }
      if (count($this->workflow->languages(array_keys($job['languages']))) !== count($job['languages'])) {
        throw new \RuntimeException('A selected language is no longer available.');
      }
      if ($job['phase'] === 'prepare') { $this->prepare($job); }
      elseif ($job['phase'] === 'translate') { $this->translate($job); }
      elseif ($job['phase'] === 'import') { $this->import($job); }
      else { throw new \RuntimeException('Unknown translation job phase.'); }
      $job['updated'] = time();
      $job['error'] = FALSE;
      $this->save($job);
      if (in_array($job['state'], ['queued', 'running', 'importing'], TRUE)) { $this->enqueue($id); }
    }
    catch (\Throwable $e) {
      // Keep diagnostics and process output out of browser-visible job data.
      if (isset($job) && $job) {
        $job['state'] = 'failed'; $job['error'] = TRUE; $job['updated'] = time();
        $languages = array_keys($job['languages']);
        $failedLanguage = $job['phase'] === 'import' ? ($job['import_language'] ?? NULL) : ($languages[$job['index']] ?? NULL);
        if ($failedLanguage !== NULL) {
          $job['languages'][$failedLanguage]['state'] = 'failed';
          $job['failed_language'] = $failedLanguage;
        }
        $job['error_code'] = match (TRUE) {
          str_contains($e->getMessage(), 'translation artifact') => 'artifact',
          str_contains($e->getMessage(), 'operator is no longer') => 'owner',
          $e instanceof \InvalidArgumentException => 'configuration',
          $e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException => 'timeout',
          str_starts_with($e->getMessage(), 'Agent returned') || str_starts_with($e->getMessage(), 'Agent response') || str_starts_with($e->getMessage(), 'Agent changed') => 'response',
          preg_match('/^(claude|codex|gemini|custom) failed /', $e->getMessage()) === 1 => 'process',
          default => 'worker',
        };
        $this->save($job);
        $this->loggerFactory->get('labdoo_translations')->error('Translation job @job failed in phase @phase (@class).', ['@job' => $id, '@phase' => $job['phase'], '@class' => get_class($e)]);
      }
    }
    finally { $this->lock->release($key); }
  }

  /** An explicit retry resumes committed batches; failed calls are not looped. */
  public function retry(string $id, AccountInterface $account): void {
    $this->mutate($id, $account, function (array &$job): void {
      if ($job['state'] !== 'failed' && !(in_array($job['state'], ['queued', 'running', 'importing'], TRUE) && $job['updated'] < time() - 360)) {
        throw new \RuntimeException('This job is not ready to retry.');
      }
      if ($job['phase'] !== 'import') { $this->agents->options($job['agent'], $job['model']); }
      $job['state'] = $job['phase'] === 'import' ? 'importing' : 'queued';
      if (isset($job['failed_language'])) {
        $job['languages'][$job['failed_language']]['state'] = match ($job['phase']) { 'prepare' => 'queued', 'import' => 'importing', default => 'prepared' };
        unset($job['failed_language']);
      }
      $job['error'] = FALSE;
    });
  }

  /** Queues import only after a separate, CSRF-protected confirmation. */
  public function requestImport(string $id, string $language, AccountInterface $account): void {
    $this->mutate($id, $account, function (array &$job) use ($language): void {
      if (($job['languages'][$language]['state'] ?? '') === 'imported' || ($job['state'] === 'importing' && ($job['import_language'] ?? '') === $language)) { return; }
      if ($job['state'] !== 'review' || ($job['languages'][$language]['state'] ?? '') !== 'review') {
        throw new \RuntimeException('Only reviewed translation results can be imported.');
      }
      $this->verified($job, $language, 'output');
      $job['phase'] = 'import'; $job['state'] = 'importing';
      $job['import_language'] = $language; $job['import_offset'] = 0;
      $job['languages'][$language]['state'] = 'importing';
    });
  }

  /** Returns only a published, checksum-verified result belonging to the job. */
  public function result(array $job, string $language): string {
    if (empty($job['languages'][$language]['output_hash'])) {
      throw new NotFoundHttpException();
    }
    return $this->verified($job, $language, 'output');
  }

  /** Parses at most one page of private results for safe, escaped review. */
  public function preview(array $job, string $language, int $page = 0): array {
    $path = $this->result($job, $language);
    // Stop the stream after the requested page rather than rendering a whole PO.
    $reader = new \Drupal\Component\Gettext\PoStreamReader();
    $reader->setURI($path); $reader->open(); $rows = []; $index = 0;
    try {
      while ($item = $reader->readItem()) {
        if ($index++ < max(0, $page) * 20) { continue; }
        $rows[] = ['source' => implode("\n", (array) $item->getSource()), 'translation' => implode("\n", (array) $item->getTranslation()), 'context' => (string) $item->getContext()];
        if (count($rows) === 20) { break; }
      }
    }
    finally { $reader->close(); }
    return $rows;
  }

  private function record(string $id): ?array {
    return preg_match('/^[a-f0-9-]{36}$/D', $id) ? $this->store->get($id) : NULL;
  }

  private function save(array $job): void { $this->store->set($job['id'], $job); }

  private function enqueue(string $id): void {
    if (!$this->queues->get(self::QUEUE)->createItem(['job_id' => $id])) {
      throw new \RuntimeException('Could not queue the translation job.');
    }
  }

  private function mutate(string $id, AccountInterface $account, callable $change): void {
    $this->load($id, $account);
    $key = 'labdoo.translation:' . $id;
    if (!$this->lock->acquire($key, 300)) { throw new \RuntimeException('The job is currently being processed.'); }
    try {
      $job = $this->load($id, $account);
      $before = $job; $change($job);
      if ($before === $job) { return; }
      $job['updated'] = time(); $this->save($job); $this->enqueue($id);
    }
    finally { $this->lock->release($key); }
  }

  private function directory(string $id, bool $create = FALSE): string {
    $uri = 'private://labdoo-translations';
    if ($create && !$this->files->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new \RuntimeException('Private file storage is not available.');
    }
    $base = $this->files->realpath($uri);
    if (!$base || !is_dir($base)) { throw new \RuntimeException('Private file storage is not available.'); }
    if (str_starts_with($base . '/', realpath(DRUPAL_ROOT) . '/')) {
      throw new \RuntimeException('Translation files require private storage outside the document root.');
    }
    $path = $base . '/' . $id;
    if ($create && !is_dir($path) && !mkdir($path, 0700)) { throw new \RuntimeException('Could not create a private job directory.'); }
    return $path;
  }

  private function path(array $job, string $language, string $kind): string {
    if (!isset($job['languages'][$language]) || !preg_match('/^[a-z][a-z0-9-]{0,19}$/D', $language) || !preg_match('/^(source|output|part-\d+)$/D', $kind)) {
      throw new \RuntimeException('Invalid translation artifact.');
    }
    return $this->directory($job['id']) . '/' . $kind . '.' . $language . '.po';
  }

  private function verified(array $job, string $language, string $kind): string {
    $path = $this->path($job, $language, $kind);
    $hash = $kind === 'source' || $kind === 'output' ? ($job['languages'][$language][$kind . '_hash'] ?? '') : ($job['languages'][$language]['parts'][$kind] ?? '');
    if (!$hash || !is_file($path) || !hash_equals($hash, (string) hash_file('sha256', $path))) {
      throw new \RuntimeException('The translation artifact is missing or has changed.');
    }
    return $path;
  }

  private function prepare(array &$job): void {
    $languages = array_keys($job['languages']); $language = $languages[$job['index']];
    $snapshot = $this->workflow->prepare($language);
    $path = $this->path($job, $language, 'source');
    $this->workflow->writeFile($path, $snapshot['header'], $snapshot['items']);
    $job['languages'][$language]['source_hash'] = hash_file('sha256', $path);
    $job['languages'][$language]['total'] = count($snapshot['items']);
    $job['languages'][$language]['state'] = $snapshot['items'] ? 'prepared' : 'empty';
    $job['state'] = 'running';
    if (++$job['index'] === count($languages)) { $job['index'] = 0; $job['phase'] = 'translate'; }
  }

  private function translate(array &$job): void {
    $languages = array_keys($job['languages']); $language = $languages[$job['index']];
    $path = $this->verified($job, $language, 'source');
    [$header, $batch, $seek] = $this->workflow->readBatch($path, $job['seek'] ?? 0, self::BATCH);
    if ($batch) {
      // Ten entries have at most sixty plural variants: one bounded CLI call.
      $translated = $this->workflow->translate($batch, $language, $header, $this->agents->options($job['agent'], $job['model']));
      $kind = 'part-' . $job['offset']; $part = $this->path($job, $language, $kind);
      $this->workflow->writeFile($part, $header, $translated);
      $job['languages'][$language]['parts'][$kind] = hash_file('sha256', $part);
      $job['offset'] += count($batch);
      $job['seek'] = $seek;
      $job['languages'][$language]['completed'] = $job['offset'];
    }
    if ($job['offset'] >= $job['languages'][$language]['total']) {
      if ($job['languages'][$language]['total']) {
        $merged = [];
        foreach ($job['languages'][$language]['parts'] as $kind => $hash) {
          [, $part] = $this->workflow->readFile($this->verified($job, $language, $kind), $language, $language);
          array_push($merged, ...$part);
        }
        $output = $this->path($job, $language, 'output');
        $this->workflow->writeFile($output, $header, $merged);
        $job['languages'][$language]['output_hash'] = hash_file('sha256', $output);
        $job['languages'][$language]['state'] = 'review';
      }
      $job['offset'] = 0;
      $job['seek'] = 0;
      if (++$job['index'] === count($languages)) { $job['state'] = 'review'; }
    }
  }

  private function import(array &$job): void {
    $language = $job['import_language'];
    $this->verified($job, $language, 'output');
    $kinds = array_keys($job['languages'][$language]['parts']);
    $kind = $kinds[$job['import_offset']] ?? NULL;
    if ($kind !== NULL) {
      $path = $this->verified($job, $language, $kind);
      $this->modules->loadInclude('locale', 'inc', 'locale.bulk');
      $report = Gettext::fileToDatabase((object) ['uri' => $path, 'langcode' => $language], ['customized' => LOCALE_CUSTOMIZED, 'overwrite_options' => ['customized' => FALSE, 'not_customized' => FALSE]]);
      if ($report['skips']) { throw new \RuntimeException('Some translations were rejected by Locale.'); }
      _locale_refresh_translations([$language], $report['strings']);
      $job['import_offset']++;
    }
    if ($job['import_offset'] >= count($kinds)) {
      $job['languages'][$language]['state'] = 'imported';
      $states = array_column($job['languages'], 'state');
      $job['state'] = array_diff($states, ['imported', 'empty']) ? 'review' : 'imported';
    }
  }

}
