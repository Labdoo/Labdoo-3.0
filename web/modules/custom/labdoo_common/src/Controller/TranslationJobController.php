<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\labdoo_common\Service\TranslationJobs;
use Drupal\labdoo_common\Service\TranslationWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Shows live job state and owner-authorized private translation artifacts. */
final class TranslationJobController extends ControllerBase {

  public function __construct(private TranslationJobs $jobs, private TranslationWorkflow $workflow) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('labdoo_common.translation_jobs'), $container->get('labdoo_common.translation_workflow'));
  }

  public function view(string $job): array {
    $record = $this->jobs->load($job, $this->currentUser());
    [$done, $total, $counts] = $this->progress($record);
    $build = ['#cache' => ['max-age' => 0, 'contexts' => ['user']], '#attached' => ['library' => ['labdoo_common/translation_jobs']]];
    $build['agent'] = ['#markup' => '<p>' . $this->t('Agent: @agent; model: @model.', ['@agent' => $record['agent_label'] ?? $record['agent'], '@model' => $record['model'] ?: $this->t('Agent default model')]) . '</p>'];
    $build['state'] = ['#type' => 'container', '#attributes' => ['data-translation-job' => $record['state'], 'data-job-phase' => $record['phase'], 'data-status-url' => Url::fromRoute('labdoo_common.translation_status', ['job' => $job])->toString()]];
    $build['state']['label'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->jobs->label($record['state']), '#attributes' => ['data-job-status' => '', 'role' => 'status']];
    $build['state']['progress'] = ['#type' => 'html_tag', '#tag' => 'progress', '#attributes' => ['value' => $done, 'max' => max(1, $total), 'aria-label' => $this->t('Translation progress'), 'data-job-progress' => '']];
    $build['state']['counts'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $counts, '#attributes' => ['data-job-counts' => '']];
    if ($record['phase'] === 'prepare' && in_array($record['state'], ['queued', 'running'], TRUE)) {
      $build['preparing'] = ['#markup' => '<p>' . $this->t('Preparing input files. Totals will be available when all languages have been prepared.') . '</p>'];
    }
    if ($record['state'] === 'importing') {
      $build['import_progress'] = ['#markup' => '<p>' . $this->t('Importing @language: @done batches processed.', ['@language' => $record['import_language'], '@done' => $record['import_offset']]) . '</p>'];
    }
    if ($record['error']) {
      $build['error'] = ['#markup' => '<p role="alert">' . $this->jobs->failureMessage($record) . '</p>'];
    }
    $rows = []; $languages = $this->workflow->languages();
    foreach ($record['languages'] as $language => $result) {
      $links = [];
      if (!empty($result['output_hash'])) {
        $links['review'] = ['#type' => 'link', '#title' => $this->t('Review @language', ['@language' => $language]), '#url' => Url::fromRoute('labdoo_common.translation_review', ['job' => $job, 'langcode' => $language]), '#suffix' => ' '];
        $links['download'] = ['#type' => 'link', '#title' => $this->t('Download @language PO', ['@language' => $language]), '#url' => Url::fromRoute('labdoo_common.translation_download', ['job' => $job, 'langcode' => $language]), '#suffix' => ' '];
        if ($record['state'] === 'review' && $result['state'] === 'review') {
          $links['import'] = ['#type' => 'link', '#title' => $this->t('Import @language', ['@language' => $language]), '#url' => Url::fromRoute('labdoo_common.translation_action', ['job' => $job, 'action' => 'import', 'langcode' => $language])];
        }
      }
      $rows[] = [($languages[$language] ?? NULL)?->getName() ?? $language,
        ['data' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->jobs->label($result['state']), '#attributes' => ['data-language-status' => $language]]],
        ['data' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $result['completed'] . ' / ' . $result['total'], '#attributes' => ['data-language-progress' => $language]]], ['data' => $links]];
    }
    $build['results'] = ['#type' => 'table', '#header' => [$this->t('Language'), $this->t('Status'), $this->t('Entries'), $this->t('Actions')], '#rows' => $rows, '#attributes' => ['class' => ['translation-results']]];
    if ($record['state'] === 'failed' || (in_array($record['state'], ['queued', 'running', 'importing'], TRUE) && $record['updated'] < time() - 360)) {
      $build['retry'] = ['#type' => 'link', '#title' => $this->t('Retry unfinished work'), '#url' => Url::fromRoute('labdoo_common.translation_action', ['job' => $job, 'action' => 'retry', 'langcode' => '-']), '#suffix' => '<br>'];
    }
    $build['refresh'] = ['#type' => 'link', '#title' => $this->t('Refresh status'), '#url' => Url::fromRoute('labdoo_common.translation_job', ['job' => $job]), '#suffix' => '<br>'];
    $build['back'] = ['#type' => 'link', '#title' => $this->t('All translation jobs'), '#url' => Url::fromRoute('labdoo_common.translations')];
    return $build;
  }

  public function status(string $job): JsonResponse {
    $record = $this->jobs->load($job, $this->currentUser());
    [$done, $total, $counts] = $this->progress($record);
    $languages = [];
    foreach ($record['languages'] as $language => $result) {
      $languages[$language] = ['label' => (string) $this->jobs->label($result['state']), 'completed' => $result['completed'], 'total' => $result['total']];
    }
    $response = new JsonResponse(['state' => $record['state'], 'phase' => $record['phase'], 'updated' => $record['updated'], 'completed' => $done, 'total' => $total, 'label' => (string) $this->jobs->label($record['state']), 'counts' => (string) $counts, 'languages' => $languages]);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  private function progress(array $job): array {
    if ($job['phase'] === 'import' && in_array($job['state'], ['importing', 'failed'], TRUE)) {
      $done = $job['import_offset']; $total = count($job['languages'][$job['import_language']]['parts']);
      return [$done, $total, $this->t('@done of @total import batches processed', ['@done' => $done, '@total' => $total])];
    }
    $done = array_sum(array_column($job['languages'], 'completed'));
    $total = array_sum(array_column($job['languages'], 'total'));
    return [$done, $total, $this->t('@done of @total entries processed', ['@done' => $done, '@total' => $total])];
  }

  public function download(string $job, string $langcode): BinaryFileResponse {
    $record = $this->jobs->load($job, $this->currentUser());
    try { $path = $this->jobs->result($record, $langcode); }
    catch (\RuntimeException $e) { throw new HttpException(409, (string) $this->t('The translation file is unavailable or has changed.')); }
    $response = new BinaryFileResponse($path);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'labdoo_custom.' . $langcode . '.po');
    $response->headers->set('Content-Type', 'text/x-gettext-translation; charset=UTF-8');
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

  public function review(string $job, string $langcode, Request $request): array {
    $record = $this->jobs->load($job, $this->currentUser());
    $page = max(0, min(100000, (int) $request->query->get('page', 0)));
    try { $entries = $this->jobs->preview($record, $langcode, $page); }
    catch (\RuntimeException $e) { throw new HttpException(409, (string) $this->t('The translation file is unavailable or has changed.')); }
    $rows = [];
    foreach ($entries as $entry) {
      $rows[] = array_map(static fn(string $text): array => ['data' => ['#plain_text' => $text], 'class' => ['translation-review-cell']], $entry);
    }
    $build = ['#cache' => ['max-age' => 0, 'contexts' => ['user']], '#attached' => ['library' => ['labdoo_common/translation_jobs']]];
    $build['intro'] = ['#markup' => '<p>' . $this->t('Review the generated translations before importing. Existing translations will be preserved during import.') . '</p>'];
    $build['region'] = ['#type' => 'container', '#attributes' => ['role' => 'region', 'aria-label' => $this->t('Translation preview'), 'tabindex' => 0, 'class' => ['translation-preview']]];
    $build['region']['table'] = ['#type' => 'table', '#header' => [$this->t('Source'), $this->t('Translation'), $this->t('Context')], '#rows' => $rows, '#empty' => $this->t('No entries on this page.')];
    if ($page > 0) { $build['previous'] = ['#type' => 'link', '#title' => $this->t('Previous page'), '#url' => Url::fromRoute('labdoo_common.translation_review', ['job' => $job, 'langcode' => $langcode], ['query' => ['page' => $page - 1]]), '#suffix' => ' ']; }
    if (($page + 1) * 20 < $record['languages'][$langcode]['total']) { $build['next'] = ['#type' => 'link', '#title' => $this->t('Next page'), '#url' => Url::fromRoute('labdoo_common.translation_review', ['job' => $job, 'langcode' => $langcode], ['query' => ['page' => $page + 1]]), '#suffix' => '<br>']; }
    $build['back'] = ['#type' => 'link', '#title' => $this->t('Back to job'), '#url' => Url::fromRoute('labdoo_common.translation_job', ['job' => $job])];
    return $build;
  }

}
