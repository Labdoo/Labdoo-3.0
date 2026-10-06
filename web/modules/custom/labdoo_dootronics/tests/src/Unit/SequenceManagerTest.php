<?php

namespace Drupal\Tests\labdoo_dootronics\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\labdoo_dootronics\Service\SequenceManager;

/**
 * @coversDefaultClass \Drupal\labdoo_dootronics\Service\SequenceManager
 * @group labdoo_dootronics
 */
class SequenceManagerTest extends UnitTestCase {

  /**
   * Existing gaps and duplicate labels must never be reused.
   *
   * @covers ::get
   */
  public function testNextIdFollowsMaximum(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');

    $result = new class {
      public function fetchField(): string {
        return '63348';
      }
    };
    $query = $this->createMock(SelectInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->expects($this->once())->method('addExpression')
      ->with('MAX(CAST(n.title AS UNSIGNED))', 'max_label');
    $query->method('execute')->willReturn($result);

    $database = $this->createMock(Connection::class);
    $database->method('select')->with('node_field_data', 'n')->willReturn($query);

    $manager = new SequenceManager($lock, $database);
    $this->assertSame(63349, $manager->get());
    $manager->commit();
  }

}
