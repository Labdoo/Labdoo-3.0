<?php

namespace Drupal\labdoo_dootronics\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a bulk upload form for creating dootronics from a JSON file.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class UpdateDootronicForm extends FormBase {

  /**
   * The dootronic repository.
   *
   * @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface
   */
  protected DootronicRepositoryInterface $dootronicRepository;

  /**
   * Constructs a BulkUploadForm form object.
   *
   * @param \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository
   *   The dootronic repository.
   */
  public function __construct(
    DootronicRepositoryInterface $dootronicRepository
  ) {
    $this->dootronicRepository = $dootronicRepository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('labdoo_dootronics.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'labdoo_dootronics_update_dootronic_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $view_id = NULL, $display_id = NULL) {
    $form['#attached']['library'][] = 'labdoo_dootronics/dootronics_forms_header';
    $form['header_image'] = [
      '#type' => 'markup',
      '#markup' => '<div class="labdoo-dootronics-form-header"><img src="' . base_path() . 'themes/custom/labdoo/img/upload-image.png" alt="' . $this->t('Dootronics forms header image') . '"></div>',
      '#weight' => -100,
    ];

    $form['file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('File'),
      '#upload_location' => 'public://temp',
      '#progress_message' => $this->t('Processing...'),
      '#upload_validators' => [
        'file_validate_extensions' => ['json'],
      ],
      '#required' => TRUE,
    ];
    $form['edoovillage_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Only update the 'status' and 'edoovillage' fields of each dootronic"),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Upload and execute'),
      '#submit' => [
        [$this, 'submitForm'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $fid = $form_state->getValue('file');
    if (!$fid) {
      $form_state->setErrorByName('file', $this->t('File is required'));

      return;
    }

    $file = File::load(reset($fid));
    if (!$file) {
      $form_state->setErrorByName('file', $this->t('The file could not be loaded'));

      return;
    }

    $file_path = $file->getFileUri();

    // Reads the file content.
    $data = file_get_contents($file_path);
    if ($data === FALSE) {
      $form_state->setErrorByName('file', $this->t('Unable to read the file.'));

      return;
    }

    // Validates the JSON data.
    $data = json_decode($data, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
      $form_state->setErrorByName('file', $this->t('The file must contain a valid JSON.'));

      return;
    }

    // Saves the JSON content in the form state.
    $form_state->setTemporaryValue('json_data', $data);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $dootronicsData = $form_state->getTemporaryValue('json_data');
    if (!$dootronicsData) {
      $this->messenger()->addError($this->t('No valid JSON data found.'));

      return;
    }

    foreach ($dootronicsData as $singleDootronicData) {
      if (!isset($singleDootronicData['labdoo_id'])) {
        continue;
      }

      $dootronicLabel = $singleDootronicData['labdoo_id'];
      if (strlen($dootronicLabel) < 9) {
        $dootronicLabel = str_pad($dootronicLabel, 9, '0', STR_PAD_LEFT);
      }
      $dootronic = $this->dootronicRepository->loadByLabel($dootronicLabel);
      if ($dootronic === NULL) {
        $this->messenger()->addError(
          $this->t(
            'Unable to load dootronic :did1.',
            [':did1' => $dootronicLabel]
          )
        );

        continue;
      }

      $this->dootronicRepository->setDootronicExternalData(
        $dootronic,
        $singleDootronicData,
        $dootronicLabel,
        $form_state->getValue('edoovillage_only') ?? FALSE
      );
      if (!$this->dootronicRepository->saveEntity($dootronic)) {
        $this->messenger()->addError($this->t('Unable to save dootronic.'));
      }
      else {
        $this->messenger()->addMessage(
          $this->t(
            'Dootronic :did1 was correctly updated.',
            [':did1' => $dootronic->label()]
          )
        );
      }
    }
  }

}
