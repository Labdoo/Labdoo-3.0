<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\labdoo_common\Service\TranslationAgentRegistry;
use Drupal\labdoo_common\Service\TranslationJobs;
use Drupal\labdoo_common\Service\TranslationWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Selects languages and authorized server-side translation agents. */
final class TranslationJobsForm extends FormBase {

  public function __construct(protected TranslationWorkflow $workflow, protected TranslationAgentRegistry $agents, protected TranslationJobs $jobs, protected UuidInterface $uuid) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('labdoo_common.translation_workflow'), $container->get('labdoo_common.translation_agents'), $container->get('labdoo_common.translation_jobs'), $container->get('uuid'));
  }

  public function getFormId(): string { return 'labdoo_translation_jobs'; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $languages = $this->workflow->languages();
    $profiles = $this->agents->profiles();
    $form['intro'] = ['#markup' => '<p>' . $this->t('Check missing translations, create a background translation job, then review its files before importing.') . '</p>'];
    $form['languages'] = ['#type' => 'checkboxes', '#title' => $this->t('Target languages'), '#options' => array_map(static fn($language) => $language->getName(), $languages), '#required' => TRUE];
    $form['agent'] = ['#type' => 'select', '#title' => $this->t('Translation agent'), '#options' => array_map(static fn(array $profile) => $profile['label'], $profiles), '#empty_option' => $this->t('- Select an agent -'), '#limit_validation_errors' => [['agent']], '#ajax' => ['callback' => '::models', 'wrapper' => 'translation-agent-model']];
    $selected = (string) $form_state->getValue('agent', '');
    $models = $profiles[$selected]['models'] ?? [];
    $form['model_container'] = ['#type' => 'container', '#attributes' => ['id' => 'translation-agent-model']];
    $form['model_container']['model'] = ['#type' => 'select', '#title' => $this->t('Model'), '#options' => ['' => $this->t('Agent default model')] + array_combine($models, $models)];
    $form['request_id'] = ['#type' => 'hidden', '#default_value' => $form_state->get('translation_request') ?: $this->uuid->generate()];
    $form_state->set('translation_request', $form['request_id']['#default_value']);
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = ['#type' => 'submit', '#value' => $this->t('Check pending strings'), '#submit' => ['::preview'], '#name' => 'preview'];
    $form['actions']['start'] = ['#type' => 'submit', '#value' => $this->t('Start translation'), '#name' => 'start', '#disabled' => !$profiles];
    if (!$profiles) { $form['availability'] = ['#markup' => '<p>' . $this->t('No translation agents are available. Ask a site administrator to configure an agent.') . '</p>']; }
    if ($counts = $form_state->get('pending_counts')) {
      $rows = [];
      foreach ($counts as $language => $count) { $rows[] = [$languages[$language]->getName(), $count]; }
      $form['pending'] = ['#type' => 'table', '#caption' => $this->t('Pending strings'), '#header' => [$this->t('Language'), $this->t('Untranslated entries')], '#rows' => $rows];
    }
    $rows = [];
    foreach ($this->jobs->recent($this->currentUser()) as $job) {
      $rows[] = [
        ['data' => ['#type' => 'link', '#title' => $this->t('Job created @date', ['@date' => date('Y-m-d H:i:s', $job['created'])]), '#url' => Url::fromRoute('labdoo_common.translation_job', ['job' => $job['id']])]],
        implode(', ', array_keys($job['languages'])),
        $this->jobs->label($job['state']),
      ];
    }
    $form['recent'] = ['#type' => 'table', '#caption' => $this->t('Recent jobs'), '#header' => [$this->t('Job'), $this->t('Languages'), $this->t('Status')], '#rows' => $rows, '#empty' => $this->t('No translation jobs yet.')];
    return $form;
  }

  public function models(array &$form, FormStateInterface $form_state): array { return $form['model_container']; }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $languages = array_values(array_filter((array) $form_state->getValue('languages')));
    if (!$languages) { $form_state->setErrorByName('languages', $this->t('Select at least one target language.')); }
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'start') {
      try { $this->agents->options((string) $form_state->getValue('agent'), (string) $form_state->getValue('model')); }
      catch (\InvalidArgumentException $e) { $form_state->setErrorByName('agent', $this->t('Select an available agent and model.')); }
    }
  }

  public function preview(array &$form, FormStateInterface $form_state): void {
    $counts = [];
    foreach (array_filter((array) $form_state->getValue('languages')) as $language) { $counts[$language] = count($this->workflow->pending($language)); }
    $form_state->set('pending_counts', $counts)->setRebuild();
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $id = $this->jobs->create($this->currentUser(), array_values(array_filter((array) $form_state->getValue('languages'))), (string) $form_state->getValue('agent'), (string) $form_state->getValue('model'), (string) $form_state->getValue('request_id'));
      $form_state->setRedirect('labdoo_common.translation_job', ['job' => $id]);
    }
    catch (\RuntimeException | \InvalidArgumentException $e) {
      $this->messenger()->addError($this->t('The job could not be created. Check protected storage and the worker configuration, then try again.'));
      $form_state->setRebuild();
    }
  }

}
