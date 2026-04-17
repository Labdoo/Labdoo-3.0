<?php

namespace Drupal\labdoo_common\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Footer Acts' block.
 *
 * @Block(
 *   id = "labdoo_footer_acts_block",
 *   admin_label = @Translation("Labdoo Footer Acts"),
 *   category = @Translation("Labdoo"),
 * )
 */
class FooterActsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'text-align: center;',
        'class' => ['footer-acts-container'],
      ],
      'image' => [
        '#theme' => 'image',
        '#uri' => '/' . \Drupal::service('extension.list.theme')->getPath('labdoo') . '/img/footer-acts.png',
        '#alt' => $this->t('Labdoo Footer Acts'),
        '#attributes' => [
          'class' => ['footer-acts-image'],
        ],
      ],
    ];
  }

}
