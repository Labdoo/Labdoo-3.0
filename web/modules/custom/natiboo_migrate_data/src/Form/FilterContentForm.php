<?php

namespace Drupal\natiboo_migrate_data\Form;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for filtering content based on entity type and bundle.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class FilterContentForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new ExportContentController instance.
   *
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The pager manager service.
   * @param \Drupal\Core\Path\CurrentPathStack $currentPathStack
   *   The current path stack.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected PagerManagerInterface $pagerManager,
    protected CurrentPathStack $currentPathStack,
    protected AliasManagerInterface $aliasManager,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('pager.manager'),
      $container->get('path.current'),
      $container->get('path_alias.manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'filter_content_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $currentEntityType = $this->getRequest()->query->get(
      'entity_type',
      'node'
    );
    $currentBundle = $this->getRequest()->query->get(
      'bundle',
      ''
    );

    $entityTypes = $this->entityTypeManager->getDefinitions();
    $contentEntityTypes = array_filter($entityTypes, function ($type) {
      return $type instanceof ContentEntityTypeInterface;
    });
    $entityTypeOptions = array_map(function ($type) {
      return $type->getLabel();
    }, $contentEntityTypes);

    try {
      $bundles = $this->entityTypeManager
        ->getStorage('node_type')
        ->loadMultiple();

      $bundleOptions = array_map(function ($bundle) {
        return $bundle->label();
      }, $bundles);
    }
    catch (\Exception $e) {
      // Registrar el error y mostrar un mensaje al usuario.
      $this->getLogger('natiboo_migrate_data')->error('Error loading bundles: @message', [
        '@message' => $e->getMessage(),
      ]);

      $this->messenger()->addError($this->t(
        'An error occurred while loading the bundle options. Please check the logs for details.'
      ));

      return $form;
    }

    $form['entity_type'] = [
      '#type' => 'select',
      '#method' => 'get',
      '#action' => $this->getRedirectUrl(),
      '#title' => $this->t('Filter by entity type'),
      '#options' => $entityTypeOptions,
      '#default_value' => $currentEntityType,
      '#attributes' => [
        'onchange' => 'this.form.submit()',
      ],
    ];

    $form['bundle'] = [
      '#type' => 'select',
      '#method' => 'get',
      '#action' => $this->getRedirectUrl(),
      '#title' => $this->t('Filter by bundle'),
      '#options' => $bundleOptions,
      '#default_value' => $currentBundle,
      '#attributes' => [
        'onchange' => 'this.form.submit()',
      ],
    ];

    return $form;
  }

  /**
   * Retrieves a redirect URL by resolving the alias of the current path.
   *
   * @return string
   *   The resolved alias corresponding to the current path.
   */
  private function getRedirectUrl(): string {
    $currentPath = $this->currentPathStack->getPath();

    return $this->aliasManager->getAliasByPath($currentPath);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
