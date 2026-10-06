<?php

declare(strict_types=1);

namespace Drupal\Core\Entity {
  interface EntityInterface {
    public function hasField($name);
    public function get($name);
  }
}

namespace Drupal\geocoder {
  interface GeocoderInterface {}
}

namespace Drupal\labdoo_common\Service\Repository {
  class CommonRepository {}
}

namespace {
  use Drupal\Core\Entity\EntityInterface;
  use Drupal\labdoo_global_action\Service\ActionGenerator\AbstractActionGenerator;

  require dirname(__DIR__) . '/src/Service/ActionGenerator/AbstractActionGenerator.php';

  final class TestFieldList {
    public function __construct(public bool $empty, public string $value = '') {}
    public function isEmpty(): bool { return $this->empty; }
  }

  final class TestEntity implements EntityInterface {
    public function __construct(private array $fields) {}
    public function hasField($name): bool { return array_key_exists($name, $this->fields); }
    public function get($name): TestFieldList { return $this->fields[$name]; }
  }

  final class TestActionGenerator extends AbstractActionGenerator {
    public int $reverseCalls = 0;
    public function __construct(private array $reverseResult) {}
    protected function reverseGeocodeWithFallback(string $lat, string $lon): ?array {
      $this->reverseCalls++;
      return $this->reverseResult;
    }
    public function resolve(EntityInterface $entity, array $location, &$city, &$country): void {
      $this->resolveLocalGeoData($entity, $location, $city, $country);
    }
  }

  $testCases = [
    'fills missing country and preserves city' => [
      new TestEntity(['field_city' => new TestFieldList(FALSE, 'Madrid'), 'field_country' => new TestFieldList(TRUE)]),
      ['lat' => '40.4168', 'lon' => '-3.7038'], ['city' => 'Madrid', 'country_code' => 'ES'], 'Madrid', 'ES',
    ],
    'fills missing city and preserves country' => [
      new TestEntity(['field_city' => new TestFieldList(TRUE), 'field_country' => new TestFieldList(FALSE, 'ES')]),
      ['lat' => '40.4168', 'lon' => '-3.7038'], ['city' => 'Madrid', 'country_code' => 'FR'], 'Madrid', 'ES',
    ],
    'does not geocode when both values exist' => [
      new TestEntity(['field_city' => new TestFieldList(FALSE, 'Madrid'), 'field_country' => new TestFieldList(FALSE, 'ES')]),
      ['lat' => '40.4168', 'lon' => '-3.7038'], ['city' => 'Other', 'country_code' => 'FR'], 'Madrid', 'ES',
    ],
  ];

  foreach ($testCases as $label => [$entity, $location, $geo, $expectedCity, $expectedCountry]) {
    $generator = new TestActionGenerator($geo);
    $city = $country = '';
    $generator->resolve($entity, $location, $city, $country);
    if ($city !== $expectedCity || $country !== $expectedCountry) {
      throw new \RuntimeException(sprintf('%s: got %s/%s', $label, $city, $country));
    }
    $expectedCalls = $label === 'does not geocode when both values exist' ? 0 : 1;
    if ($generator->reverseCalls !== $expectedCalls) {
      throw new \RuntimeException(sprintf('%s: expected %d geocoder calls, got %d', $label, $expectedCalls, $generator->reverseCalls));
    }
    echo "PASS: {$label}\n";
  }
}
