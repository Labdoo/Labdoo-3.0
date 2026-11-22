<?php

namespace Drupal\labdoo_common\Plugin\Block;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Footer Acts' block.
 *
 * @phpstan-consistent-constructor
 *
 * @Block(
 *   id = "labdoo_footer_acts_block",
 *   admin_label = @Translation("Labdoo Footer Acts"),
 *   category = @Translation("Labdoo"),
 * )
 */
class FooterActsBlock extends BlockBase implements ContainerFactoryPluginInterface {
  /**
   * The extension.list.theme service.
   */
  protected ThemeExtensionList $themes;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->themes = $container->get('extension.list.theme');
    return $instance;
  }

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
        '#uri' => '/' . $this->themes->getPath('labdoo') . '/img/footer-acts.png',
        '#alt' => $this->t('Labdoo Footer Acts'),
        '#attributes' => [
          'class' => ['footer-acts-image'],
        ],
      ],
    ];
  }

}
