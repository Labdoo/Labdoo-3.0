<?php

namespace Drupal\labdoo_dootronics\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Clones a Dootronic.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class CloneDootronicForm extends FormBase {

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
    return 'labdoo_dootronics_clone_dootronic_form';
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

    $form['original_dootronic'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Enter the ID of the dootronic you want to clone'),
      '#target_type' => 'node',
      '#selection_settings' => [
        'target_bundles' => ['dootronic'],
      ],
      '#default_value' => $form_state->getValue('original_dootronic'),
      '#required' => TRUE,
    ];
    $form['dootronics_number'] = [
      '#type' => 'number',
      '#title' => $this->t('Enter the quantity of clones you want to generate'),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Clone'),
      '#submit' => [
        [$this, 'submitForm'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $originalDootronicId = $form_state->getValue('original_dootronic');
    $originalDootronic = $this->dootronicRepository->load($originalDootronicId);
    if ($originalDootronic === NULL) {
      $this->messenger()->addError($this->t('Unable to load the original dootronic.'));

      return;
    }

    for ($i = 0; $i < $form_state->getValue('dootronics_number'); ++$i) {
      $clonedDootronic = $this->dootronicRepository->clone($originalDootronic);
      if (!$this->dootronicRepository->saveEntity($clonedDootronic)) {
        $this->messenger()->addError($this->t('Unable to save dootronic.'));
      }
      else {
        $this->messenger()->addMessage(
          $this->t(
            'Dootronic :did1 was correctly created.',
            [':did1' => $clonedDootronic->toLink($clonedDootronic->label())->toString()]
          ),
          TRUE
        );
      }
    }
  }

}
