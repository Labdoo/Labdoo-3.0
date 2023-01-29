<?php

namespace Drupal\github_issues\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritDoc}
   */
  public function getFormId(): string {
    return 'github_issues_settings';
  }

  /**
   * {@inheritDoc}
   */
  protected function getEditableConfigNames(): array {
    return ['github_issues.settings'];
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('github_issues.settings');

    $form['key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Key'),
      '#description' => $this->t('Select a key to use for authentication.'),
      '#default_value' => $config->get('key'),
      '#required' => TRUE,
    ];

    $form['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GitHub App ID'),
      '#description' => $this->t('GitHub > Settings > Developer settings > GitHub App > Find your app and click in Edit.'),
      '#default_value' => $config->get('app_id'),
      '#required' => TRUE,
    ];

    $form['installation_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GitHub Installation ID'),
      '#description' => $this->t('GitHub > Settings > Applications > Find your app and click on Configure. The installation ID is in the URL.'),
      '#default_value' => $config->get('installation_id'),
      '#required' => TRUE,
    ];

    $form['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GitHub User'),
      '#description' => $this->t('Enter your GitHub username.'),
      '#default_value' => $config->get('user'),
      '#required' => TRUE,
    ];

    $form['repository'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GitHub Repository'),
      '#description' => $this->t('Enter the name of the GitHub repository (e.g., owner/repo).'),
      '#default_value' => $config->get('repository'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $this->config('github_issues.settings')
      ->set('key', $form_state->getValue('key'))
      ->set('app_id', $form_state->getValue('app_id'))
      ->set('installation_id', $form_state->getValue('installation_id'))
      ->set('user', $form_state->getValue('user'))
      ->set('repository', $form_state->getValue('repository'))
      ->save();
  }
}
