<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Twig;

use Drupal\labdoo_common\Asset\VersionedAssets;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Supplies content-addressed URLs for public theme artwork.
 */
final class VersionedAssetExtension extends AbstractExtension {

  /**
   * Constructs the extension.
   */
  public function __construct(private readonly VersionedAssets $assets) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [new TwigFunction('labdoo_asset', $this->url(...))];
  }

  /**
   * Returns an escaped-by-Twig URL using the active Drupal base path.
   */
  public function url(string $path): string {
    return base_path() . $this->assets->path(ltrim($path, '/'));
  }

}
