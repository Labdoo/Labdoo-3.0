<?php

namespace Drupal\labdoo_map\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'Labdoo Map' block.
 *
 * @Block(
 *   id = "labdoo_map_block",
 *   admin_label = @Translation("Labdoo Map"),
 *   category = @Translation("Labdoo"),
 * )
 */
class LabdooMapBlock extends BlockBase {

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
    
    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'map-' . $type,
        'class' => ['labdoo-map-container'],
        'data-labdoo-map-type' => $type,
        'style' => 'height: 400px; width: 100%;',
      ],
      '#attached' => [
        'library' => [
          'labdoo/labdoo_map',
        ],
      ],
    ];
  }

}
