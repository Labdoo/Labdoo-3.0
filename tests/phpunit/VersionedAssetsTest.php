<?php

declare(strict_types=1);

namespace Drupal\Tests\labdoo_common\Unit;

use Drupal\labdoo_common\Asset\VersionedAssets;
use PHPUnit\Framework\TestCase;

/**
 * Tests public URL fallback and manifest boundary validation.
 */
final class VersionedAssetsTest extends TestCase {

  /**
   * Missing optional artifacts retain working original URLs.
   */
  public function testMissingManifestPreservesOriginalPath(): void {
    $resolver = new VersionedAssets('/nonexistent-labdoo-static-fixture');
    self::assertSame('themes/custom/labdoo_next/js/lazy-search.js', $resolver->path('themes/custom/labdoo_next/js/lazy-search.js'));
  }

  /**
   * A malformed manifest cannot redirect public URLs outside the static tree.
   */
  public function testManifestRejectsTraversal(): void {
    $root = sys_get_temp_dir() . '/labdoo-asset-fixture-' . bin2hex(random_bytes(8));
    $folder = $root . '/themes/custom/labdoo_next/static';
    mkdir($folder, 0700, TRUE);
    $file = $folder . '/manifest.json';
    file_put_contents($file, json_encode(['example.js' => '../private/settings.php']));
    try {
      $this->expectException(\UnexpectedValueException::class);
      (new VersionedAssets($root))->path('example.js');
    }
    finally {
      unlink($file);
      foreach ([$folder, dirname($folder), dirname($folder, 2), dirname($folder, 3), $root] as $directory) {
        rmdir($directory);
      }
    }
  }

}
