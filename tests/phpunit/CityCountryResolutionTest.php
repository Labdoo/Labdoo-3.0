<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\labdoo_global_action\Service\ActionGenerator\AbstractActionGenerator;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Checks local geography precedence using actual Drupal interface doubles.
 */
#[CoversClass(AbstractActionGenerator::class)]
#[Group('labdoo_global_action')]
class CityCountryResolutionTest extends UnitTestCase {

  /**
   * Provides the five maintained local geography and sentinel contracts.
   */
  public static function geography(): array {
    return [
      'fills missing country and preserves city' => [
        ['field_city' => [FALSE, 'Madrid'], 'field_country' => [TRUE, '']],
        ['lat' => '40.4168', 'lon' => '-3.7038'],
        ['city' => 'Madrid', 'country_code' => 'ES'], 'Madrid', 'ES', 1,
      ],
      'fills missing city and preserves country' => [
        ['field_city' => [TRUE, ''], 'field_country' => [FALSE, 'ES']],
        ['lat' => '40.4168', 'lon' => '-3.7038'],
        ['city' => 'Madrid', 'country_code' => 'FR'], 'Madrid', 'ES', 1,
      ],
      'does not geocode when both values exist' => [
        ['field_city' => [FALSE, 'Madrid'], 'field_country' => [FALSE, 'ES']],
        ['lat' => '40.4168', 'lon' => '-3.7038'],
        ['city' => 'Other', 'country_code' => 'FR'], 'Madrid', 'ES', 0,
      ],
      'does not geocode invalid sentinel' => [
        [], ['lat' => '-90', 'lon' => '-180'],
        ['city' => 'Wrong', 'country_code' => 'XX'], '', '', 0,
      ],
      'does not geocode invalid range' => [
        [], ['lat' => '91', 'lon' => '181'],
        ['city' => 'Wrong', 'country_code' => 'XX'], '', '', 0,
      ],
    ];
  }

  /**
   * Preserves known data and avoids provider calls for complete/invalid input.
   */
  #[DataProvider('geography')]
  public function testResolve(array $fields, array $location, array $geo, string $expectedCity, string $expectedCountry, int $expectedCalls): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnCallback(static fn ($name) => array_key_exists($name, $fields));
    $node->method('get')->willReturnCallback(function ($name) use ($fields): FieldItemListInterface {
      [$empty, $value] = $fields[$name];
      $field = $this->createMock(FieldItemList::class);
      $field->method('isEmpty')->willReturn($empty);
      $field->method('__get')->with('value')->willReturn($value);
      return $field;
    });
    $generator = new class($geo) extends AbstractActionGenerator {

      /**
       * Number of attempted fallback calls.
       */
      public int $reverseCalls = 0;

      /**
       * Supplies a controlled provider result without external services.
       */
      public function __construct(private array $reverseResult) {}

      /**
       * {@inheritdoc}
       */
      protected function reverseGeocodeWithFallback(string $lat, string $lon): array {
        $this->reverseCalls++;
        return $this->reverseResult;
      }

      /**
       * Exercises the real local-resolution implementation.
       */
      public function resolve(NodeInterface $node, array $location, &$city, &$country): void {
        $this->resolveLocalGeoData($node, $location, $city, $country);
      }

    };
    $city = $country = '';
    $generator->resolve($node, $location, $city, $country);
    $this->assertSame($expectedCity, $city);
    $this->assertSame($expectedCountry, $country);
    $this->assertSame($expectedCalls, $generator->reverseCalls);
  }

}
