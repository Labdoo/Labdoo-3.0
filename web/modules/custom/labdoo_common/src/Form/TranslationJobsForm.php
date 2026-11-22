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
use Drupal\labdoo_common\Service\TranslationEstimate;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Selects languages and authorized server-side translation agents.
 */
final class TranslationJobsForm extends FormBase {

  public function __construct(protected TranslationWorkflow $workflow, protected TranslationAgentRegistry $agents, protected TranslationJobs $jobs, protected UuidInterface $uuid, protected TranslationEstimate $estimator) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('labdoo_common.translation_workflow'), $container->get('labdoo_common.translation_agents'), $container->get('labdoo_common.translation_jobs'), $container->get('uuid'), $container->get('labdoo_common.translation_estimate'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'labdoo_translation_jobs';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $languages = $this->workflow->languages();
    $profiles = $this->agents->profiles();
    $form['#attached']['library'][] = 'labdoo_common/translation_jobs';
    $form['#attributes']['data-translation-estimate-form'] = '';
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Check missing translations, create a background translation job, then review its files before importing.') . '</p>',
    ];
    $form['languages'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Target languages'),
      '#options' => array_map(static fn($language) => $language->getName(), $languages),
      '#required' => TRUE,
    ];
    $form['agent'] = ['#type' => 'select', '#title' => $this->t('Translation agent'), '#options' => array_map(static fn(array $profile) => $profile['label'], $profiles), '#empty_option' => $this->t('- Select an agent -'), '#limit_validation_errors' => [[
      'agent',
    ]], '#ajax' => ['callback' => '::models', 'wrapper' => 'translation-agent-model']];
    $selected = (string) $form_state->getValue('agent', '');
    $models = $profiles[$selected]['models'] ?? [];
    $form['model_container'] = ['#type' => 'container', '#attributes' => ['id' => 'translation-agent-model']];
    $form['model_container']['model'] = ['#type' => 'select', '#title' => $this->t('Model'), '#options' => [
      '' => $this->t('Agent default model'),
    ] + array_combine($models, $models)];
    $form['request_id'] = [
      '#type' => 'hidden',
      '#default_value' => $form_state->get('translation_request') ?: $this->uuid->generate(),
    ];
    $form_state->set('translation_request', $form['request_id']['#default_value']);
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = ['#type' => 'submit', '#value' => $this->t('Check pending strings'), '#submit' => [
      '::preview',
    ], '#name' => 'preview'];
    $form['actions']['estimate'] = ['#type' => 'submit', '#value' => $this->t('Estimate cost'), '#submit' => [
      '::estimate',
    ], '#name' => 'estimate', '#disabled' => !$profiles];
    $estimate = $form_state->get('translation_estimate');
    $selection = $this->selection($form_state);
    if ($estimate && $estimate['selection'] !== $selection) {
      $form_state->set('translation_estimate', NULL);
      $estimate = NULL;
    }
    $form['actions']['start'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start translation'),
      '#name' => 'start',
      '#disabled' => !$profiles || !$estimate,
    ];
    if ($estimate) {
      $form['estimate_review'] = $this->review($estimate, $languages, $profiles);
      $form['estimate_review']['confirm_estimate'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I have reviewed the estimated usage and billing information and want to start this translation.'),
      ];
    }
    $form['estimate_changed'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('The selection has changed. Estimate again before starting.'), '#attributes' => [
      'hidden' => TRUE,
      'role' => 'status',
      'data-estimate-changed' => '',
    ]];
    if (!$profiles) {
      $form['availability'] = [
        '#markup' => '<p>' . $this->t('No translation agents are available. Ask a site administrator to configure an agent.') . '</p>',
      ];
    }
    if ($counts = $form_state->get('pending_counts')) {
      $rows = [];
      foreach ($counts as $language => $count) {
        $rows[] = [$languages[$language]->getName(), $count];
      }
      $form['pending'] = ['#type' => 'table', '#caption' => $this->t('Pending strings'), '#header' => [
        $this->t('Language'),
        $this->t('Untranslated entries'),
      ], '#rows' => $rows];
    }
    $rows = [];
    foreach ($this->jobs->recent($this->currentUser()) as $job) {
      $rows[] = [
        ['data' => ['#type' => 'link', '#title' => $this->t('Job created @date', [
          '@date' => date('Y-m-d H:i:s', $job['created']),
        ]), '#url' => Url::fromRoute('labdoo_common.translation_job', [
          'job' => $job['id'],
        ])]],
        implode(', ', array_keys($job['languages'])),
        $this->jobs->label($job['state']),
      ];
    }
    $form['recent'] = ['#type' => 'table', '#caption' => $this->t('Recent jobs'), '#header' => [
      $this->t('Job'),
      $this->t('Languages'),
      $this->t('Status'),
    ], '#rows' => $rows, '#empty' => $this->t('No translation jobs yet.')];
    foreach ([
      'intro' => 0,
      'languages' => 10,
      'pending' => 15,
      'agent' => 20,
      'model_container' => 30,
      'estimate_review' => 40,
      'estimate_changed' => 41,
      'actions' => 50,
      'recent' => 60,
    ] as $key => $weight) {
      if (isset($form[$key])) {
        $form[$key]['#weight'] = $weight;
      }
    }
    return $form;
  }

  /**
   * Rebuilds the agent's model selector for the AJAX callback.
   */
  public function models(array &$form, FormStateInterface $form_state): array {
    return $form['model_container'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $languages = array_values(array_filter((array) $form_state->getValue('languages')));
    if (!$languages) {
      $form_state->setErrorByName('languages', $this->t('Select at least one target language.'));
    }
    $action = $form_state->getTriggeringElement()['#name'] ?? '';
    if (in_array($action, ['start', 'estimate'], TRUE)) {
      try {
        $this->agents->options((string) $form_state->getValue('agent'), (string) $form_state->getValue('model'));
      }
      catch (\InvalidArgumentException $e) {
        $form_state->setErrorByName('agent', $this->t('Select an available agent and model.'));
      }
    }
    if ($action === 'start' && !$form_state->hasAnyErrors()) {
      $reviewed = $form_state->get('translation_estimate');
      try {
        $fresh = $this->estimator->calculate($languages, (string) $form_state->getValue('agent'), (string) $form_state->getValue('model'));
        if (!$reviewed || !hash_equals($reviewed['fingerprint'], $fresh['fingerprint'])) {
          $form_state->set('translation_estimate', NULL);
          $form_state->setErrorByName('agent', $this->t('The selection, pending translations or pricing have changed. Estimate again before starting.'));
        }
        elseif (!$form_state->getValue('confirm_estimate')) {
          $form_state->setErrorByName('confirm_estimate', $this->t('Review and confirm the estimated usage and billing information before starting.'));
        }
      }
      catch (\RuntimeException | \InvalidArgumentException $e) {
        $form_state->set('translation_estimate', NULL);
        $form_state->setErrorByName('agent', $this->t('The estimate could not be checked. Estimate again before starting.'));
      }
    }
  }

  /**
   * Stores the selected languages' pending-string preview in form state.
   */
  public function preview(array &$form, FormStateInterface $form_state): void {
    $form_state->set('translation_estimate', NULL);
    $counts = [];
    foreach (array_filter((array) $form_state->getValue('languages')) as $language) {
      $counts[$language] = count($this->workflow->pending($language));
    }
    $form_state->set('pending_counts', $counts)->setRebuild();
  }

  /**
   * Builds a locally computed review step without queueing or charging.
   */
  public function estimate(array &$form, FormStateInterface $form_state): void {
    try {
      $selection = $this->selection($form_state);
      $estimate = $this->estimator->calculate($selection['languages'], $selection['agent'], $selection['model']);
      $form_state->set('translation_estimate', $estimate);
      $form_state->setValue('confirm_estimate', 0);
      $form_state->setUserInput(array_diff_key($form_state->getUserInput(), ['confirm_estimate' => TRUE]));
    }
    catch (\RuntimeException | \InvalidArgumentException $e) {
      $form_state->set('translation_estimate', NULL);
      $this->messenger()->addError($this->t('The estimate could not be calculated. Check the selected languages and billing information.'));
    }
    $form_state->setRebuild();
  }

  /**
   * Normalizes selected languages, agent and model from form state.
   */
  private function selection(FormStateInterface $state): array {
    $selected = array_filter((array) $state->getValue('languages'));
    return [
      'languages' => $selected ? array_keys($this->workflow->languages(array_values($selected))) : [],
      'agent' => (string) $state->getValue('agent', ''),
      'model' => (string) $state->getValue('model', ''),
    ];
  }

  /**
   * Builds a review summary for the selected translation estimate.
   */
  private function review(array $estimate, array $languages, array $profiles): array {
    $build = ['#type' => 'fieldset', '#title' => $this->t('Estimated translation cost'), '#attributes' => [
      'data-translation-estimate' => '',
    ]];
    $build['selection'] = ['#markup' => '<p>' . $this->t('Agent: @agent; model: @model.', [
      '@agent' => $profiles[$estimate['selection']['agent']]['label'],
      '@model' => $estimate['selection']['model'] ?: $this->t('Agent default model'),
    ]) . '</p>'];
    $pricing = $estimate['pricing'];
    $build['basis'] = [
      '#markup' => '<p>' . $this->t('Indicative range calculated locally from missing translations and application prompts. This is not a quote or spending limit. CLI context, reasoning, retries, taxes and provider billing rules may change actual usage and cost. Pending strings may change before queued work is processed.') . '</p>',
    ];
    if ($pricing['billing'] === 'subscription') {
      $build['billing'] = [
        '#markup' => '<p>' . $this->t('Subscription billing: estimated tokens may consume plan limits. An additional monetary charge cannot be determined from this estimate; subscription access does not mean unlimited or free usage.') . '</p>',
      ];
    }
    elseif ($pricing['billing'] !== 'metered') {
      $build['billing'] = [
        '#markup' => '<p>' . $this->t('No usage tariff is available for this selection. The monetary cost cannot be estimated.') . '</p>',
      ];
    }
    else {
      $build['billing'] = ['#markup' => '<p>' . $this->t('Configured rates per million tokens: @input input / @output output @currency. Reference date: @date.', [
        '@input' => $pricing['input_per_million'],
        '@output' => $pricing['output_per_million'],
        '@currency' => $pricing['currency'],
        '@date' => $pricing['as_of'] ?: $this->t('Not provided'),
      ]) . '</p>'];
      if ($pricing['source']) {
        $build['source'] = ['#plain_text' => $pricing['source']];
      }
    }
    $rows = [];
    foreach ($estimate['rows'] + ['total' => $estimate['total']] as $language => $row) {
      $range = static fn(int $min, int $max): string => number_format($min) . '–' . number_format($max);
      $cost = $row['cost_max'] === NULL ? $this->t('Not available') : $pricing['currency'] . ' ' . number_format(floor($row['cost_min'] * 1000000) / 1000000, 6) . '–' . number_format(ceil($row['cost_max'] * 1000000) / 1000000, 6);
      $rows[] = [
        $language === 'total' ? $this->t('Total') : $languages[$language]->getName(),
        $row['entries'],
        $row['calls'],
        $range($row['input_min'], $row['input_max']),
        $range($row['output_min'], $row['output_max']),
        $cost,
      ];
    }
    $build['table'] = ['#type' => 'table', '#caption' => $this->t('Estimated usage and cost'), '#header' => [
      $this->t('Language'),
      $this->t('Entries'),
      $this->t('Requests'),
      $this->t('Input tokens'),
      $this->t('Output tokens'),
      $this->t('Estimated cost'),
    ], '#rows' => $rows, '#prefix' => '<div class="translation-preview" tabindex="0" role="region" aria-label="' . $this->t('Estimated usage and cost') . '">', '#suffix' => '</div>'];
    return $build;
  }

  /**
   * {@inheritdoc}
   */
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
