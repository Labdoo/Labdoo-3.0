<?php

declare(strict_types=1);

namespace Drupal\mini_wiki\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for a wiki page entity type.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
final class MiniWikiPageSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['mini_wiki_page.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mini_wiki_page_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mini_wiki_page.settings');

    // Get all wiki pages.
    $storage = \Drupal::entityTypeManager()->getStorage('mini_wiki_page');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('label', 'ASC');

    $ids = $query->execute();

    $options = ['' => $this->t('- None (auto-detect) -')];
    if (!empty($ids)) {
      $pages = $storage->loadMultiple($ids);
      foreach ($pages as $page) {
        $options[$page->id()] = $page->label();
      }
    }

    $form['root_page'] = [
      '#type' => 'select',
      '#title' => $this->t('Root page'),
      '#description' => $this->t('Select the root page for the mini wiki. If not set, the first page without parent will be used.'),
      '#options' => $options,
      '#default_value' => $config->get('root_page') ?? '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('mini_wiki_page.settings')
      ->set('root_page', $form_state->getValue('root_page'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
