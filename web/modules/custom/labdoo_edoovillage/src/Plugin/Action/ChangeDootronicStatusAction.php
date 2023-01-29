<?php

namespace Drupal\labdoo_edoovillage\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Drupal\labdoo_edoovillage\Service\Permission\DootronicPermissionChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Action to change the dootronic status.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Action(
 *   id = "labdoo_edoovillage_change_dootronic_status",
 *   label = @Translation("Change dootronic status"),
 *   type = "node",
 *   category = @Translation("Labdoo")
 * )
 */
class ChangeDootronicStatusAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * The permission checker service.
   *
   * @var \Drupal\labdoo_edoovillage\Service\Permission\DootronicPermissionChecker
   */
  protected $permissionChecker;

  /**
   * The dootronic repository service.
   *
   * @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface
   */
  protected $dootronicRepository;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new ChangeDootronicStatusAction object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\labdoo_edoovillage\Service\Permission\DootronicPermissionChecker $permission_checker
   *   The permission checker service.
   * @param \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronic_repository
   *   The dootronic repository service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DootronicPermissionChecker $permission_checker,
    DootronicRepositoryInterface $dootronic_repository,
    AccountInterface $current_user
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->permissionChecker = $permission_checker;
    $this->dootronicRepository = $dootronic_repository;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('labdoo_edoovillage.permission.dootronic'),
      $container->get('labdoo_dootronics.repository'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'status' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Get the allowed values for the field_dootronic_status field
    $allowedValues = $this->dootronicRepository->getfieldAllowedValues('node', 'dootronic', 'field_dootronic_status');

    // Prepare options for the select field
    $options = array_map(function($value) {
      return $this->t($value);
    }, $allowedValues);

    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => $options,
      '#default_value' => $this->configuration['status'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['status'] = $form_state->getValue('status');
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity && $entity->hasField('field_dootronic_status')) {
      $entity->set('field_dootronic_status', $this->configuration['status']);
      $entity->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: $this->currentUser;
    $access = AccessResult::forbidden();

    if ($object && $object->hasField('field_dootronic_status')) {
      $access = AccessResult::allowedIf($this->permissionChecker->canChangeDootronicStatus($object, $account));
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

}
