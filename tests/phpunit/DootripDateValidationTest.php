<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests chronology validation using processed and raw datetime widget values.
 */
#[CoversFunction('labdoo_dootrip_validate_date_order')]
#[CoversFunction('labdoo_dootrip_parse_date_value')]
#[Group('labdoo_dootrip')]
class DootripDateValidationTest extends UnitTestCase {

  /**
   * Provides processed dates and raw input, including conflicting values.
   */
  public static function dates(): array {
    $early = [['value' => new \DateTimeImmutable('2027-06-01 12:00:00 UTC')]];
    $late = [['value' => new \DateTimeImmutable('2027-06-02 12:00:00 UTC')]];
    $rawEarly = [['value' => ['date' => '2027-06-01', 'time' => '12:00:00']]];
    $rawLate = [['value' => ['date' => '2027-06-02', 'time' => '12:00:00']]];
    return [
      'processed reversed' => [$late, $early, NULL, NULL, TRUE],
      'processed chronological' => [$early, $late, NULL, NULL, FALSE],
      'equal dates' => [$early, $early, NULL, NULL, FALSE],
      'raw reversed' => [NULL, NULL, $rawLate, $rawEarly, TRUE],
      'raw chronological' => [NULL, NULL, $rawEarly, $rawLate, FALSE],
      'processed values take precedence' => [$late, $early, $rawEarly, $rawLate, TRUE],
      'processed valid over raw reversed' => [$early, $late, $rawLate, $rawEarly, FALSE],
      'missing dates' => [NULL, NULL, NULL, NULL, FALSE],
      'one missing date' => [$late, NULL, NULL, NULL, FALSE],
      'empty processed value uses raw' => [[], [], $rawLate, $rawEarly, TRUE],
    ];
  }

  /**
   * Rejects reversed dates without reporting errors for valid or absent dates.
   */
  #[DataProvider('dates')]
  public function testDateOrder(?array $departure, ?array $arrival, ?array $rawDeparture, ?array $rawArrival, bool $reversed): void {
    require_once dirname(__DIR__, 2) . '/web/modules/custom/labdoo_dootrip/labdoo_dootrip.module';
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
    foreach ([&$departure, &$arrival] as &$field) {
      if (isset($field[0]['value']) && $field[0]['value'] instanceof \DateTimeInterface) {
        $field[0]['value'] = new DrupalDateTime($field[0]['value']->format('c'), NULL, ['langcode' => 'en']);
      }
    }
    unset($field);
    $state = new FormState();
    $state->setValues(['field_departure_date' => $departure, 'field_arrival_date' => $arrival]);
    $state->setUserInput(['field_departure_date' => $rawDeparture, 'field_arrival_date' => $rawArrival]);
    $form = [];
    try {
      labdoo_dootrip_validate_date_order($form, $state);
      $this->assertSame($reversed, isset($state->getErrors()['field_departure_date']));
      $this->assertCount($reversed ? 1 : 0, $state->getErrors());
    }
    finally {
      $state->clearErrors();
    }
  }

  /**
   * Keeps native dates, timezone offsets and raw string values compatible.
   */
  public function testDateRepresentations(): void {
    require_once dirname(__DIR__, 2) . '/web/modules/custom/labdoo_dootrip/labdoo_dootrip.module';
    $native = new \DateTimeImmutable('2027-06-01T12:00:00+02:00');
    $processed = new DrupalDateTime('2027-06-01T12:00:00+02:00', NULL, ['langcode' => 'en']);
    foreach ([$native, $processed, '2027-06-01T12:00:00+02:00'] as $value) {
      $parsed = labdoo_dootrip_parse_date_value([['value' => $value]]);
      $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
      $this->assertSame($native->getTimestamp(), $parsed->getTimestamp());
    }
    $invalid = new DrupalDateTime('not a valid date', NULL, ['langcode' => 'en']);
    $this->assertNull(labdoo_dootrip_parse_date_value([['value' => $invalid]]));
    $this->assertNull(labdoo_dootrip_parse_date_value([['value' => 'not a valid date']]));
  }

}
