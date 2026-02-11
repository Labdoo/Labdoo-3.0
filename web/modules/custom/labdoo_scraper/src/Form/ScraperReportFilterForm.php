<?php

namespace Drupal\labdoo_scraper\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a filter form for the scraper report.
 */
class ScraperReportFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'labdoo_scraper_report_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter results'),
      '#open' => TRUE,
    ];

    $form['filters']['source_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source URL'),
      '#default_value' => \Drupal::request()->query->get('source_url'),
    ];

    $form['filters']['slug'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slug'),
      '#default_value' => \Drupal::request()->query->get('slug'),
    ];

    $form['filters']['exists'] = [
      '#type' => 'select',
      '#title' => $this->t('Exists'),
      '#options' => [
        '' => $this->t('- Any -'),
        '1' => $this->t('Yes'),
        '0' => $this->t('No'),
      ],
      '#default_value' => \Drupal::request()->query->get('exists'),
    ];

    $form['filters']['entity_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity Type'),
      '#default_value' => \Drupal::request()->query->get('entity_type'),
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
    ];

    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];

    $form['filters']['actions']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
      '#submit' => ['::resetForm'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $query = [];
    $values = $form_state->getValues();
    
    if (!empty($values['source_url'])) {
      $query['source_url'] = $values['source_url'];
    }
    if (!empty($values['slug'])) {
      $query['slug'] = $values['slug'];
    }
    if ($values['exists'] !== '') {
      $query['exists'] = $values['exists'];
    }
    if (!empty($values['entity_type'])) {
      $query['entity_type'] = $values['entity_type'];
    }

    $form_state->setRedirect('labdoo_scraper.report', [], ['query' => $query]);
  }

  /**
   * Resets the form filters.
   */
  public function resetForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('labdoo_scraper.report');
  }

}
