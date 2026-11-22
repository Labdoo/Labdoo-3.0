<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit\Support;

use Drupal\labdoo_global_action\Service\ActionGenerator\AbstractActionGenerator;
use Drupal\node\NodeInterface;

/**
 * Exposes protected action boundaries without changing their implementations.
 */
final class ActionGeneratorProbe extends AbstractActionGenerator {

  /**
   * Reads an action's nullable numeric node reference.
   */
  public function reference(NodeInterface $node, string $field): ?int {
    return $this->referenceNodeId($node, $field);
  }

  /**
   * Gets configured provider objects.
   *
   * @return \Drupal\geocoder\GeocoderProviderInterface[]
   *   Available providers.
   */
  public function providers(): array {
    return $this->geocodingProviders();
  }

  /**
   * Exercises primary reverse geocoding, including its empty-result boundary.
   */
  public function reverseResult(string $lat, string $lon): ?array {
    return $this->reverseGeocode($lat, $lon);
  }

}
