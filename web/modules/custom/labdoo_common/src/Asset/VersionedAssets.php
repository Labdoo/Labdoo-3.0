<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Asset;

/**
 * Resolves generated public assets without changing unversioned fallbacks.
 */
final class VersionedAssets {

  /**
   * The reviewed source-to-content-addressed-path map.
   *
   * @var array<string, string>|null
   */
  private ?array $manifest = NULL;

  /**
   * Constructs the resolver using Drupal's document root.
   */
  public function __construct(private readonly string $appRoot) {}

  /**
   * Resolves a path relative to the Drupal document root.
   */
  public function path(string $path): string {
    return $this->all()[ltrim($path, '/')] ?? $path;
  }

  /**
   * Returns the map of generated public assets.
   *
   * @return array<string, string>
   *   Original paths keyed to immutable copies.
   */
  public function all(): array {
    if ($this->manifest === NULL) {
      $this->manifest = [];
      $file = $this->appRoot . '/themes/custom/labdoo_next/static/manifest.json';
      if (is_file($file)) {
        $map = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
        if (!is_array($map)) {
          throw new \UnexpectedValueException('Invalid immutable asset manifest.');
        }
        foreach ($map as $source => $target) {
          if (!is_string($source) || !is_string($target) || str_contains($target, '..') || !preg_match('@^themes/custom/labdoo_next/static/(?:vendor/)?[a-f0-9]{64}/[a-zA-Z0-9_./-]+$@D', $target)) {
            throw new \UnexpectedValueException('Invalid immutable asset manifest.');
          }
          $this->manifest[$source] = $target;
        }
      }
    }
    return $this->manifest;
  }

}
