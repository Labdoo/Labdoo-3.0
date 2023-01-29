<?php

namespace Drupal\natiboo_migrate_data\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\natiboo_migrate_data\Service\MenuImport;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for importing a menu via a JSON file.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ImportMenuForm extends FormBase {

  /**
   * Constructs the ImportDataForm object, injecting the request stack.
   *
   * @param \Drupal\natiboo_migrate_data\Service\MenuImport $menuImportService
   *   The menu import service.
   */
  public function __construct(
    protected MenuImport $menuImportService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('natiboo_migrate_data.menu.import')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'natiboo_migrate_menu_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['file'] = [
      '#type' => 'file',
      '#title' => $this->t('Upload JSON File'),
      '#description' => $this->t('Upload the exported JSON file containing menu or content data.'),
    ];

    $form['overwrite'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Overwrite existing records?'),
      '#description' => $this->t('Check this box to overwrite existing data if conflicts are found.'),
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Data'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    try {
      $this->menuImportService->getFileData();
    }
    catch (\Exception $e) {
      $form_state->setErrorByName(
        'file',
        $this->t(
          'Error reading file: @error',
          ['@error' => $e->getMessage()]
        )
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $data = $this->menuImportService->getFileData();
    }
    catch (\Exception $e) {
      $this->messenger()->addError(
        $this->t(
          'Error reading file: @error',
          ['@error' => $e->getMessage()]
        )
      );

      return;
    }

    try {
      $overwrite = $form_state->getValue('overwrite');
      $this->menuImportService->import($data, $overwrite);
      $this->messenger()->addStatus($this->t('Menu imported successfully.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError(
        $this->t(
          'Error importing the menu data: @error',
          ['@error' => $e->getMessage()]
        )
      );
    }
  }

}
