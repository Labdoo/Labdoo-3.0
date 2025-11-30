<?php

namespace Drupal\labdoo_filters\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * entity_autocomplete views filter.
 *
 * @ViewsFilter("related_entity_autocomplete")
 */
class RelatedEntityAutocomplete extends FilterPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    // Entidad destino del autocomplete (hub, edoovillage, dootrip...).
    $options['target_entity_type'] = ['default' => 'node'];
    $options['target_bundles'] = ['default' => []];

    // Entidad origen de la relación (dootronic).
    $options['source_entity_type'] = ['default' => 'node'];
    $options['source_bundle'] = ['default' => 'dootronic'];
    $options['source_reference_field'] = ['default' => ''];

    // Tabla/columna donde se guarda el target_id.
    $options['reference_table'] = ['default' => ''];
    $options['reference_target_column'] = ['default' => ''];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['target_entity_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Target entity type'),
      '#description' => $this->t('Machine name of the target entity type (e.g. node).'),
      '#default_value' => $this->options['target_entity_type'],
      '#required' => TRUE,
    ];

    $form['target_bundles'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Target bundles'),
      '#description' => $this->t('Comma-separated list of target bundles (e.g. hub,edoovillage,dootrip).'),
      '#default_value' => implode(',', (array) $this->options['target_bundles']),
    ];

    $form['source_entity_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source entity type'),
      '#description' => $this->t('Machine name of the source entity type (e.g. node).'),
      '#default_value' => $this->options['source_entity_type'],
      '#required' => TRUE,
    ];

    $form['source_bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source bundle'),
      '#description' => $this->t('Bundle of the source entity (e.g. dootronic).'),
      '#default_value' => $this->options['source_bundle'],
      '#required' => TRUE,
    ];

    $form['source_reference_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source reference field'),
      '#description' => $this->t('Name of the entity reference field in the source bundle (e.g. field_hub).'),
      '#default_value' => $this->options['source_reference_field'],
      '#required' => TRUE,
    ];

    $form['reference_table'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reference field table'),
      '#description' => $this->t('The storage table for the entity reference field (e.g. node__field_hub).'),
      '#default_value' => $this->options['reference_table'],
      '#required' => TRUE,
    ];

    $form['reference_target_column'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reference target column'),
      '#description' => $this->t('The column that stores the target ID (e.g. field_hub_target_id).'),
      '#default_value' => $this->options['reference_target_column'],
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);

    $bundles_string = $form_state->getValue(['options', 'target_bundles']);
    $bundles = array_filter(array_map('trim', explode(',', (string) $bundles_string)));
    $this->options['target_bundles'] = $bundles;
  }

  /**
   * {@inheritdoc}
   */
  public function operatorOptions() {
    return [
      'in' => $this->t('Is one of'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function canExpose() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function valueForm(&$form, FormStateInterface $form_state) {
    $target_type = $this->options['target_entity_type'] ?: 'node';

    if (!$this->entityTypeManager->hasDefinition($target_type)) {
      $form['value'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Filter misconfigured: invalid target entity type "@type".', [
          '@type' => $target_type,
        ]),
      ];
      return;
    }

    $this->options['target_entity_type'] = $target_type;

    $default_entity = NULL;
    if (!empty($this->value)) {
      $storage = $this->entityTypeManager->getStorage($target_type);
      $ids = is_array($this->value) ? $this->value : [$this->value];
      $entities = $storage->loadMultiple($ids);
      if ($entities) {
        $default_entity = reset($entities);
      }
    }

    $selection_plugin_id = 'related_entity_to_source:' . $target_type;

    $form['value'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Related entity'),
      '#target_type' => $target_type,
      '#default_value' => $default_entity,
      '#required' => FALSE,
      '#tags' => FALSE,
      '#selection_handler' => $selection_plugin_id,
      '#selection_settings' => [
        'target_bundles' => $this->options['target_bundles'],
        'source_entity_type' => $this->options['source_entity_type'],
        'source_bundle' => $this->options['source_bundle'],
        'source_reference_field' => $this->options['source_reference_field'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    $identifier = $this->options['expose']['identifier'] ?? $this->options['id'];

    if (!isset($input[$identifier]) || $input[$identifier] === '' || $input[$identifier] === NULL) {
      $this->value = [];
      return FALSE;
    }

    $value = $input[$identifier];

    if (is_array($value)) {
      $this->value = array_map('intval', $value);
    }
    else {
      $this->value = [(int) $value];
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    if (empty($this->value)) {
      return;
    }

    $reference_table = $this->options['reference_table'];
    $reference_target_column = $this->options['reference_target_column'];

    if (!$reference_table || !$reference_target_column) {
      return;
    }

    // Ensure the reference field table.
    $ref_alias = $this->query->ensureTable($reference_table, $this->relationship);

    // WHERE node__field_xxx.field_xxx_target_id IN (:ids).
    $this->query->addWhere(
      $this->options['group'],
      "$ref_alias.$reference_target_column",
      $this->value,
      'IN'
    );
  }

}
