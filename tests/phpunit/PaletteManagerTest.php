<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\labdoo_theme_preview\PaletteManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers palette compatibility, CSS safety and readable Learning colors.
 */
#[CoversClass(PaletteManager::class)]
#[Group('labdoo_theme_preview')]
class PaletteManagerTest extends UnitTestCase {

  /**
   * Old saved palettes remain valid without the optional role colors.
   */
  public function testLegacyCustomPalette(): void {
    $colors = PaletteManager::presets()['ocean']['colors'];
    unset($colors['supporting'], $colors['highlight'], $colors['title']);
    $colors['green'] = '#135a83';
    foreach (['custom_123456abcdef', 'ocean'] as $id) {
      $factory = $this->getConfigFactoryStub([
        'labdoo_theme_preview.palettes' => [
          'active' => $id,
          'custom' => [$id => ['label' => 'Saved palette', 'colors' => $colors]],
        ],
      ]);
      $active = (new PaletteManager($factory))->active();
      $this->assertSame($id, $active['id']);
      $this->assertSame($colors['green'], $active['colors']['supporting']);
      $this->assertSame($colors['orange'], $active['colors']['highlight']);
      $this->assertSame($colors['green'], $active['colors']['title']);
    }
  }

  /**
   * New accent fields retain the same strict hexadecimal validation.
   */
  public function testRejectsUnsafeDecorativeColor(): void {
    $colors = PaletteManager::presets()['learning']['colors'];
    $colors['highlight'] = 'url(https://example.com/tracker)';
    $factory = $this->getConfigFactoryStub([
      'labdoo_theme_preview.palettes' => [
        'active' => 'custom_123456abcdef',
        'custom' => ['custom_123456abcdef' => ['label' => 'Invalid palette', 'colors' => $colors]],
      ],
    ]);
    $this->assertSame('forest', (new PaletteManager($factory))->active()['id']);
  }

  /**
   * Text and buttons meet normal-text contrast; yellow stays decorative.
   */
  public function testLearningContrast(): void {
    $colors = PaletteManager::presets()['learning']['colors'];
    $pairs = [
      ['title', 'paper'],
      ['title', 'canvas'],
      ['green', 'paper'],
      ['green', 'soft'],
      ['supporting', 'paper'],
      ['supporting', 'canvas'],
      ['orange', 'paper'],
      ['ink', 'canvas'],
      ['muted', 'canvas'],
      ['paper', 'primary-hover'],
    ];
    foreach ($pairs as [$text, $background]) {
      $a = $this->luminance($colors[$text]);
      $b = $this->luminance($colors[$background]);
      $this->assertGreaterThanOrEqual(4.5, (max($a, $b) + 0.05) / (min($a, $b) + 0.05), $text . ' on ' . $background);
    }
    $accents = array_intersect_key($colors, array_flip([
      'green', 'orange', 'supporting', 'highlight',
    ]));
    $this->assertCount(4, array_unique($accents));
  }

  /**
   * Computes WCAG relative luminance from a validated hexadecimal color.
   */
  private function luminance(string $hex): float {
    $channels = array_map(static function (string $value): float {
      $channel = hexdec($value) / 255;
      return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }, str_split(substr($hex, 1), 2));
    return $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
  }

}
