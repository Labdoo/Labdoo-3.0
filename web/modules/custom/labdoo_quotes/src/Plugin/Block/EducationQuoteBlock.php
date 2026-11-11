<?php

namespace Drupal\labdoo_quotes\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\labdoo_quotes\QuoteCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays a sourced education quote.
 *
 * @Block(
 *   id = "labdoo_education_quote",
 *   admin_label = @Translation("Education quote"),
 *   category = @Translation("Labdoo")
 * )
 */
final class EducationQuoteBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly QuoteCatalog $catalog) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('labdoo_quotes.catalog'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->catalog->build();
  }

}
