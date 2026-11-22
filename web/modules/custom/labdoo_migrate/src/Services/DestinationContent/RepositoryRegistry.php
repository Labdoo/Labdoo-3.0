<?php

declare(strict_types=1);

namespace Drupal\labdoo_migrate\Services\DestinationContent;

use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;

/**
 * Resolves the configured migration repositories without a global container.
 */
final class RepositoryRegistry {

  /**
   * Constructs the registry with the supported entity-type repositories.
   *
   * @param array<string, \Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface> $sources
   *   Source repositories keyed by entity type.
   * @param array<string, \Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface> $destinations
   *   Destination repositories keyed by entity type.
   */
  public function __construct(private readonly array $sources, private readonly array $destinations) {}

  /**
   * Returns the source repository for a supported entity type.
   */
  public function source(string $entityType): SourceRepositoryInterface {
    return $this->sources[$entityType] ?? throw new \InvalidArgumentException('Unsupported source entity type: ' . $entityType);
  }

  /**
   * Returns the destination repository for a supported entity type.
   */
  public function destination(string $entityType): DestinationRepositoryInterface {
    return $this->destinations[$entityType] ?? throw new \InvalidArgumentException('Unsupported destination entity type: ' . $entityType);
  }

}
