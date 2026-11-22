<?php

declare(strict_types=1);

namespace Drupal\Tests\labdoo_dootronics\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepository;
use Drupal\labdoo_dootronics\Service\SequenceManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests device repository mapping without saving entities or calling providers.
 */
#[CoversClass(DootronicRepository::class)]
#[Group('labdoo_dootronics')]
class DootronicRepositoryTest extends UnitTestCase {

  /**
   * Builds the repository with controlled services.
   */
  private function repository(?EntityFieldManagerInterface $fields = NULL, ?SequenceManagerInterface $sequence = NULL): DootronicRepository {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    return new DootronicRepository(
      $sequence ?? $this->createMock(SequenceManagerInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $factory,
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(Connection::class),
      $fields ?? $this->createMock(EntityFieldManagerInterface::class),
    );
  }

  /**
   * Provides valid and unavailable list-field settings.
   */
  public static function allowedValueCases(): array {
    return [
      'configured values' => [['allowed_values' => [1 => 'Single', 2 => 'Dual']], [1 => 'Single', 2 => 'Dual']],
      'empty values' => [['allowed_values' => []], []],
      'setting absent' => [[], []],
      'null values' => [['allowed_values' => NULL], []],
      'invalid scalar values' => [['allowed_values' => 'invalid'], []],
    ];
  }

  /**
   * Missing list settings must not produce notices or violate the array result.
   */
  #[DataProvider('allowedValueCases')]
  public function testAllowedValues(array $settings, array $expected): void {
    $field = $this->createMock(FieldConfig::class);
    $field->method('getSettings')->willReturn($settings);
    $manager = $this->createMock(EntityFieldManagerInterface::class);
    $manager->method('getFieldDefinitions')->with('node', 'dootronic')->willReturn(['field_cpu_type' => $field]);
    $this->assertSame($expected, $this->repository($manager)->getfieldAllowedValues('node', 'dootronic', 'field_cpu_type'));
  }

  /**
   * Unknown fields must return an empty list.
   */
  public function testUnknownField(): void {
    $manager = $this->createMock(EntityFieldManagerInterface::class);
    $manager->method('getFieldDefinitions')->willReturn([]);
    $this->assertSame([], $this->repository($manager)->getfieldAllowedValues('node', 'dootronic', 'missing'));
  }

  /**
   * External fields must be copied, including valid zero and empty-string data.
   */
  public function testExternalFieldsAreCopied(): void {
    $values = [];
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->never())->method('save');
    $node->method('set')->willReturnCallback(static function ($name, $value) use (&$values, $node) {
      $values[$name] = $value;
      return $node;
    });
    $this->repository()->setDootronicExternalData($node, [
      'edoovillage' => 123,
      'serial_no' => 'LOCAL-ONLY-123',
      'cpu_speed_mhz' => 0,
      'memory_size_mb' => 4096,
      'os_version' => '',
      'keyboard_layout' => 'es',
    ], '000001234', FALSE);
    $this->assertSame('000001234', $values['title']);
    $this->assertSame(123, $values['field_edoovillage_destination']);
    $this->assertSame('LOCAL-ONLY-123', $values['field_serial_number']);
    $this->assertSame(0, $values['field_cpu']);
    $this->assertSame(4096, $values['field_memory']);
    $this->assertSame('', $values['field_current_operating_system']);
    $this->assertSame('es', $values['field_keyboard_type']);
  }

  /**
   * The destination-only mode must leave unrelated device fields unchanged.
   */
  public function testDestinationOnlyMapping(): void {
    $values = ['field_serial_number' => 'keep'];
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->never())->method('save');
    $node->method('set')->willReturnCallback(static function ($name, $value) use (&$values, $node) {
      $values[$name] = $value;
      return $node;
    });
    $this->repository()->setDootronicExternalData($node, ['edoovillage' => 456, 'serial_no' => 'replace'], '000001234', TRUE);
    $this->assertSame([
      'field_serial_number' => 'keep',
      'title' => '000001234',
      'field_dootronic_status' => 'S2',
      'field_edoovillage_destination' => 456,
    ], $values);
  }

  /**
   * Provides unknown and valid battery voltages.
   */
  public static function batteryVoltages(): array {
    return [[0, NULL], ['0', NULL], [12, 833]];
  }

  /**
   * Capacity is calculated only when the supplied voltage is nonzero.
   */
  #[DataProvider('batteryVoltages')]
  public function testBatteryVoltage(int|string $voltage, ?int $expectedCapacity): void {
    $values = [];
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->never())->method('save');
    $node->method('set')->willReturnCallback(static function ($name, $value) use (&$values, $node) {
      $values[$name] = $value;
      return $node;
    });
    $this->repository()->setDootronicExternalData($node, ['battery_voltage' => $voltage, 'battery_energy_mwh' => 10000], '000001234', FALSE);
    $this->assertSame((float) $voltage, $values['field_volts']);
    if ($expectedCapacity === NULL) {
      $this->assertArrayNotHasKey('field_amp_hours', $values);
    }
    else {
      $this->assertSame($expectedCapacity, $values['field_amp_hours']);
    }
  }

  /**
   * Provides creation, update and unsuccessful storage statuses.
   */
  public static function saveStatuses(): array {
    return [[1, TRUE], [2, TRUE], [0, FALSE]];
  }

  /**
   * Storage integer statuses must retain the repository's boolean contract.
   */
  #[DataProvider('saveStatuses')]
  public function testSaveStatus(int $status, bool $expected): void {
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())->method('save')->willReturn($status);
    $this->assertSame($expected, $this->repository()->saveEntity($node));
  }

  /**
   * Storage errors must keep returning failure to callers.
   */
  public function testSaveFailure(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('save')->willThrowException(new EntityStorageException('Local test failure'));
    $this->assertFalse($this->repository()->saveEntity($node));
  }

  /**
   * Provides ordinary labels and labels longer than the minimum width.
   */
  public static function deviceLabels(): array {
    return [[1, '000000001'], [63349, '000063349'], [1000000000, '1000000000']];
  }

  /**
   * Numeric identifier formatting must preserve padding without truncation.
   */
  #[DataProvider('deviceLabels')]
  public function testDeviceLabel(int $identifier, string $expected): void {
    $sequence = $this->createMock(SequenceManagerInterface::class);
    $sequence->method('get')->willReturn($identifier);
    $sequence->expects($this->once())->method('commit');
    $this->assertSame($expected, $this->repository(NULL, $sequence)->generateId());
  }

  /**
   * Updating labels must use the same padding and commit the allocation.
   */
  #[DataProvider('deviceLabels')]
  public function testUpdatedDeviceLabel(int $identifier, string $expected): void {
    $sequence = $this->createMock(SequenceManagerInterface::class);
    $sequence->expects($this->never())->method('get');
    $sequence->expects($this->once())->method('commit');
    $this->assertSame($expected, $this->repository(NULL, $sequence)->updateId($identifier));
  }

}
