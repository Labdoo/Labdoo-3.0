<?php

namespace Drupal\labdoo_notifications\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure test email settings for the Labdoo Notifications module.
 */
class TestEmailSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'labdoo_notifications_test_email_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['labdoo_notifications.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('labdoo_notifications.settings');

    $form['test_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Test Email Address'),
      '#description' => $this->t('All emails will be sent to this address when test mode is enabled.'),
      '#default_value' => $config->get('test_email'),
      '#required' => TRUE,
    ];

    $form['test_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Test Mode'),
      '#description' => $this->t('When enabled, all emails will be sent to the test email address instead of their original recipients.'),
      '#default_value' => $config->get('test_mode'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $email = $form_state->getValue('test_email');
    if (!empty($email) && !\Drupal::service('email.validator')->isValid($email)) {
      $form_state->setErrorByName('test_email', $this->t('The email address %mail is not valid.', ['%mail' => $email]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('labdoo_notifications.settings')
      ->set('test_email', $form_state->getValue('test_email'))
      ->set('test_mode', $form_state->getValue('test_mode'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
