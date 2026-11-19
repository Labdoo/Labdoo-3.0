<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\labdoo_common\Service\TranslationJobs;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Confirms an import or retry using Drupal's normal form and CSRF checks. */
final class TranslationActionForm extends ConfirmFormBase {

  protected string $jobId;
  protected string $action;
  protected string $language;

  public function __construct(protected TranslationJobs $jobs) {}

  public static function create(ContainerInterface $container): static { return new static($container->get('labdoo_common.translation_jobs')); }

  public function getFormId(): string { return 'labdoo_translation_action'; }

  public function getQuestion() {
    return $this->action === 'import' ? $this->t('Import translations for @language?', ['@language' => $this->language]) : $this->t('Retry this translation job?');
  }

  public function getDescription() {
    return $this->action === 'import'
      ? $this->t('Import will run in the background. Existing translations are preserved; missing translations are filled from the reviewed files.')
      : $this->t('Committed batches are preserved. The unfinished batch may be sent to the agent again.');
  }

  public function getCancelUrl(): Url { return Url::fromRoute('labdoo_common.translation_job', ['job' => $this->jobId]); }

  public function buildForm(array $form, FormStateInterface $form_state, string $job = '', string $action = '', string $langcode = '-'): array {
    $record = $this->jobs->load($job, $this->currentUser());
    $this->jobId = $job; $this->action = $action; $this->language = $langcode;
    if ($action === 'import') {
      try { $this->jobs->result($record, $langcode); }
      catch (\RuntimeException $e) { throw new HttpException(409, (string) $this->t('The translation file is unavailable or has changed.')); }
    }
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      if ($this->action === 'import') { $this->jobs->requestImport($this->jobId, $this->language, $this->currentUser()); }
      else { $this->jobs->retry($this->jobId, $this->currentUser()); }
      $this->messenger()->addStatus($this->t('The requested work has been queued.'));
    }
    catch (\RuntimeException | \InvalidArgumentException $e) { $this->messenger()->addError($this->t('This action is not available or the job is currently being processed. Refresh its status and try again.')); }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
