<?php

namespace Drupal\labdoo_map\Plugin\Block;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'Labdoo Map' block.
 *
 * @phpstan-consistent-constructor
 *
 * @Block(
 *   id = "labdoo_map_block",
 *   admin_label = @Translation("Labdoo Map"),
 *   category = @Translation("Labdoo"),
 * )
 */
class LabdooMapBlock extends BlockBase implements ContainerFactoryPluginInterface {
  /**
   * The current_route_match service.
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    $form['map_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Map Type'),
      '#options' => [
        'dootronic' => $this->t('Dootronics'),
        'edoovillage' => $this->t('Edoovillages'),
        'hub' => $this->t('Hubs'),
        'dootrip' => $this->t('Dootrips'),
        'labdooer' => $this->t('Labdooers'),
      ],
      '#default_value' => $config['map_type'] ?? 'dootronic',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $this->setConfigurationValue('map_type', $form_state->getValue('map_type'));
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();
    $type = $config['map_type'] ?? 'dootronic';

    $attributes = [
      'id' => 'map-' . $type,
      'class' => ['labdoo-map-container'],
      'data-labdoo-map-type' => $type,
      'style' => 'height: 400px; width: 100%;',
    ];

    // If we are on a node page, add the NID to the attributes.
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      $attributes['data-nid'] = $node->id();
    }

    return [
      '#type' => 'container',
      '#attributes' => $attributes,
      '#attached' => [
        'library' => [
          'labdoo/labdoo_map',
        ],
      ],
    ];
  }

}
