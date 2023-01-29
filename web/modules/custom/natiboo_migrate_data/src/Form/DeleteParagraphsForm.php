<?php

namespace Drupal\natiboo_migrate_data\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\natiboo_migrate_data\Service\ParagraphDelete;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for deleting all paragraphs from the database.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DeleteParagraphsForm extends ConfirmFormBase {

  /**
   * Constructs a new DeleteParagraphsForm object.
   *
   * @param \Drupal\natiboo_migrate_data\Service\ParagraphDelete $paragraphDeleteService
   *   The paragraph delete service.
   */
  public function __construct(
    protected ParagraphDelete $paragraphDeleteService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('natiboo_migrate_data.paragraph.delete')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'natiboo_migrate_delete_paragraphs_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $count = $this->paragraphDeleteService->getParagraphCount();
    return $this->t('Are you sure you want to delete all @count paragraphs?', ['@count' => $count]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action will delete all paragraphs from the database. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('natiboo_migrate_data.entities_list');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $count = $this->paragraphDeleteService->getParagraphCount();
    
    if ($count === 0) {
      $this->messenger()->addStatus($this->t('There are no paragraphs to delete.'));
      return $this->redirect('natiboo_migrate_data.entities_list');
    }
    
    $form['count'] = [
      '#type' => 'item',
      '#markup' => $this->t('There are @count paragraphs that will be deleted.', ['@count' => $count]),
    ];
    
    $form['use_batch'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use batch processing'),
      '#description' => $this->t('Recommended for large numbers of paragraphs to prevent timeouts.'),
      '#default_value' => ($count > 100),
    ];
    
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $useBatch = $form_state->getValue('use_batch');
    $count = $this->paragraphDeleteService->deleteAllParagraphs($useBatch);
    
    if ($useBatch && $count > 100) {
      // If using batch processing, the batch process will handle the messages.
      $form_state->setRedirect('natiboo_migrate_data.entities_list');
    }
    else {
      // If not using batch processing, redirect to the entities list.
      $form_state->setRedirect('natiboo_migrate_data.entities_list');
    }
  }

}