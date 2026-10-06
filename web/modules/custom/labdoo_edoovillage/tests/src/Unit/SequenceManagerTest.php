<?php

namespace Drupal\Tests\labdoo_edoovillage\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\labdoo_edoovillage\Service\SequenceManager;

/**
 * @coversDefaultClass \Drupal\labdoo_edoovillage\Service\SequenceManager
 * @group labdoo_edoovillage
 */
class SequenceManagerTest extends UnitTestCase {

  /**
   * @dataProvider titleIdsProvider
   */
  public function testReusesFirstMissingId(array $existingIds, int $expectedId): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('labdoo.edoovillage.id');

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')
      ->willReturnOnConsecutiveCalls(...array_merge($existingIds, [FALSE]));

    $query = $this->createMock(SelectInterface::class);
    $query->method('fields')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('orderBy')->willReturnSelf();
    $query->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('select')
      ->with('node_field_data', 'n')
      ->willReturn($query);

    $manager = new SequenceManager($lock, $database);

    $this->assertSame($expectedId, $manager->get());
    $manager->commit();
  }

  /**
   * Provides existing title IDs and the next ID expected to be allocated.
   */
  public static function titleIdsProvider(): array {
    return [
      'empty sequence' => [[], 1],
      'gap after first ID' => [['Edoovillage #1 - A', 'Edoovillage #3 - C'], 2],
      'legacy starting number' => [['Edoovillage #983 - A', 'Edoovillage #984 - B'], 985],
      'deleted clone gap' => [['Edoovillage #3174 - A', 'Edoovillage #3176 - B', 'Edoovillage #3177 - C'], 3175],
      'append to complete sequence' => [['Edoovillage #1 - A', 'Edoovillage #2 - B', 'Edoovillage #3 - C'], 4],
    ];
  }

}
