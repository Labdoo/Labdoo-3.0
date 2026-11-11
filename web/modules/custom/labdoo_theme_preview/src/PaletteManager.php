<?php

namespace Drupal\labdoo_theme_preview;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Provides validated theme palettes from site configuration.
 */
final class PaletteManager {

  /**
   * Constructs the palette provider.
   */
  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * Returns the built-in palettes.
   */
  public static function presets(): array {
    return [
      'forest' => [
        'label' => 'Forest',
        'colors' => [
          'ink' => '#20332c',
          'muted' => '#59685e',
          'green' => '#245c40',
          'orange' => '#b7441c',
          'line' => '#dce4dc',
          'paper' => '#ffffff',
          'canvas' => '#f6f7f2',
          'primary-hover' => '#19442e',
          'soft' => '#edf2e9',
          'preview' => '#e9eee5',
          'control-border' => '#aebfaf',
        ],
      ],
      'ocean' => [
        'label' => 'Ocean',
        'colors' => [
          'ink' => '#203544',
          'muted' => '#526776',
          'green' => '#155e82',
          'orange' => '#a84920',
          'line' => '#d4e2ea',
          'canvas' => '#f3f7fa',
          'primary-hover' => '#104560',
          'soft' => '#e7f0f6',
          'preview' => '#dfeaf1',
          'control-border' => '#a4bdcd',
          'paper' => '#ffffff',
        ],
      ],
      'terracotta' => [
        'label' => 'Terracotta',
        'colors' => [
          'ink' => '#423029',
          'muted' => '#725c50',
          'green' => '#934225',
          'orange' => '#52632e',
          'line' => '#e7dbd2',
          'canvas' => '#fbf6f1',
          'primary-hover' => '#71321d',
          'soft' => '#f5eae0',
          'preview' => '#efe0d5',
          'control-border' => '#c9afa0',
          'paper' => '#ffffff',
        ],
      ],
      'plum' => [
        'label' => 'Plum',
        'colors' => [
          'ink' => '#392d40',
          'muted' => '#6b5d73',
          'green' => '#704080',
          'orange' => '#a24432',
          'line' => '#e3d9e8',
          'canvas' => '#f8f5fa',
          'primary-hover' => '#553060',
          'soft' => '#f0e8f4',
          'preview' => '#e9dfef',
          'control-border' => '#bda8c6',
          'paper' => '#ffffff',
        ],
      ],
    ];
  }

  /**
   * Returns presets and valid custom palettes; configuration is never CSS.
   */
  public function all(): array {
    $palettes = self::presets();
    $keys = array_keys($palettes['forest']['colors']);
    $custom = $this->configFactory->get('labdoo_theme_preview.palettes')->get('custom');
    foreach (is_array($custom) ? $custom : [] as $id => $palette) {
      if ((!isset($palettes[$id]) && !preg_match('/^custom_[a-f0-9]{12}$/D', (string) $id)) || !is_array($palette) || !is_string($palette['label'] ?? NULL) || trim($palette['label']) === '') {
        continue;
      }
      if (!is_array($palette['colors'] ?? NULL)) {
        continue;
      }
      $colors = [];
      foreach ($keys as $key) {
        $value = $palette['colors'][$key] ?? NULL;
        if (!is_string($value) || !preg_match('/^#[a-f0-9]{6}$/iD', $value)) {
          continue 2;
        }
        $colors[$key] = strtolower($value);
      }
      $palettes[$id] = ['label' => $palette['label'], 'colors' => $colors];
    }
    return $palettes;
  }

  /**
   * Returns the site-wide active palette, with a safe default.
   */
  public function active(): array {
    $palettes = $this->all();
    $id = $this->configFactory->get('labdoo_theme_preview.palettes')->get('active') ?? 'forest';
    $id = is_string($id) && isset($palettes[$id]) ? $id : 'forest';
    return ['id' => $id] + $palettes[$id];
  }

}
