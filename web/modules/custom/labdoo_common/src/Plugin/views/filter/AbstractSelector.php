<?php

namespace Drupal\labdoo_common\Plugin\views\filter;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\ViewsHandlerManager;
use Drupal\views\ViewExecutable;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AbstractSelector class provides an abstract foundation for selectors in Drupal applications.
 *
 * The selector uses a combination of services to manage and interact with the application's data,
 * including Entity Type Manager, Views Handler Manager, database connection, logger service, etc.
 */
abstract class AbstractSelector extends FilterPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The views' handler manager.
   *
   * @var \Drupal\views\Plugin\ViewsHandlerManager
   */
  protected ViewsHandlerManager $joinHandler;

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The main table.
   *
   * @var null|string
   */
  protected ?string $mainTable = '';

  /**
   * AbstractSelector constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity Type Manager Service.
   * @param \Drupal\views\Plugin\ViewsHandlerManager $join_handler
   *   Views Handler Plugin Manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    ViewsHandlerManager $join_handler,
    Connection $database,
    LoggerChannelFactoryInterface $loggerChannelFactory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->joinHandler = $join_handler;
    $this->database = $database;
    $this->logger = $loggerChannelFactory->get('labdoo_common');
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
      $configuration, $plugin_id, $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.views.join'),
      $container->get('database'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL): void {
    parent::init($view, $display, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposedForm(&$form, FormStateInterface $form_state): void {
    $form['value'] = !empty($form['value']) ? $form['value'] : [];
    parent::buildExposedForm($form, $form_state);
    $filter_id = $this->getFilterId();

    // Field which really filters.
    $form[$filter_id] = [
      '#type' => 'hidden',
      '#value' => '',
    ];

    // Auxiliary fields.
    $entityId = $this->options['entity_id'];
    $form[$entityId] = [
      '#type' => 'select',
      '#title' => $this->t($this->options['entity_label']),
      '#options' => $this->getEntitiesList(),
      '#default_value' => $this->options[$entityId] ?? NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input): bool {
    if (empty($this->options['exposed'])) {
      return TRUE;
    }

    $input[$this->options['expose']['identifier']] = $input[$this->options['entity_id']];

    return parent::acceptExposedInput($input);
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->mainTable = $this->query->ensureTable(
      $this->options['main_table'],
      $this->relationship
    );

    if (!$this->options['exposed']) {
      // Administrative value.
      $filter = $this->options[$this->options['entity_id']] ?? NULL;
      if (!empty($filter)) {
        $this->queryFilter($filter);
      }
    }
    else {
      // Exposed value.
      if (empty($this->value) || empty($this->value[0])) {
        return;
      }

      $this->queryFilter($this->value[0]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validate(): void {
    if (!empty($this->value)) {
      parent::validate();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#options' => $this->getNodeBundles(),
      '#default_value' => $this->options['bundle'] ?? NULL,
      '#required' => TRUE,
    ];
    $form['main_table'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Main table'),
      '#default_value' => $this->options['main_table'] ?? NULL,
      '#required' => TRUE,
    ];
    $form['field_target'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Field target'),
      '#default_value' => $this->options['field_target'] ?? NULL,
      '#required' => TRUE,
    ];
    $form['entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity ID'),
      '#default_value' => $this->options['entity_id'] ?? NULL,
      '#required' => TRUE,
    ];
    $form['entity_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity label'),
      '#default_value' => $this->options['entity_label'] ?? NULL,
      '#required' => TRUE,
    ];

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary(): string|TranslatableMarkup {
    // Exposed filter.
    $variables = [
      '@field' => $this->options['field'],
    ];
    if ($this->options['exposed']) {
      return $this->t('Exposed on field "@field"', $variables);
    }

    // Administrative filter.
    return $this->t('Filter on field "@field"', $variables);
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    if (!$this->options['exposed']) {
      $entityId = $this->options['entity_id'];
      $form[$entityId] = [
        '#type' => 'select',
        '#title' => $this->t($this->options['entity_label']),
        '#options' => $this->getEntitiesList(),
        '#default_value' => $this->options[$entityId] ?? NULL,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function canBuildGroup(): bool {
    return FALSE;
  }

  /**
   * Get an array of entities.
   *
   * @return array
   *   The array of entities.
   */
  protected function getEntitiesList(): array {
    $entityId = $this->options['entity_id'];
    $results = ['' => $this->t('- Any -')];
    $query = $this->database
      ->select($this->options['main_table'], $entityId);
    $query->fields('nfd', ['nid', 'title']);
    $query->innerJoin(
      'node_field_data',
      'nfd',
      'nfd.nid = ' . $entityId . '.' . $this->options['field_target']
    );
    if (!empty($this->options['bundle'])) {
      $query->condition($entityId . '.bundle', $this->options['bundle']);
    }
    try {
      $entities = $query->execute()->fetchAllAssoc('nid');
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());

      return [];
    }

    foreach ($entities as $entity) {
      $results[$entity->nid] = $entity->title;
    }

    return $results;
  }

  /**
   * This method returns the ID of the fake field which contains this plugin.
   *
   * It is important to put this ID to the exposed field of this plugin for the
   * following reasons:
   * a) To avoid problems with FilterPluginBase::acceptExposedInput function.
   * b) To allow this filter to be printed on twig templates
   *    with {{ form.date_range_picker_filter }}.
   *
   * @return string
   *   ID of the field which contains this plugin.
   */
  protected function getFilterId(): string {
    return $this->options['expose']['identifier'];
  }

  /**
   * Filters by the entity.
   *
   * @param int $entityId
   *   The entity ID.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function queryFilter(int $entityId): void {
    $this->query->addWhere("AND", "{$this->mainTable}.{$this->options['field_target']}", $entityId, "=");
  }

  /**
   * Security filter.
   *
   * @param mixed $value
   *   Input.
   *
   * @return mixed
   *   Sanitized value of input.
   */
  protected function securityFilter($value) {
    $value = Html::escape($value);

    return Xss::filter($value);
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['bundle'] = ['default' => ''];
    $options['main_table'] = ['default' => ''];
    $options['field_target'] = ['default' => ''];
    $options['entity_id'] = ['default' => ''];
    $options['entity_label'] = ['default' => ''];

    return $options;
  }

  /**
   * Get all node bundles.
   *
   * @return array
   *   An array of node bundles.
   */
  protected function getNodeBundles(): array {
    $nodeBundles = [];

    try {
      $nodeTypes = $this->entityTypeManager->getStorage('node_type')
        ->loadMultiple();

      foreach ($nodeTypes as $type) {
        $nodeBundles[$type->id()] = $type->label();
      }
    } catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Error getting the bundles: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);
    }

    return $nodeBundles;
  }

}
