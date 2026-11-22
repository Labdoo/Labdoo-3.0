<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\labdoo_dootrip\Service\Queue\Feeder\AbstractQueueFeeder;
use Drupal\labdoo_dootrip\Service\Queue\Feeder\DootripCapacityQueueFeeder;
use Drupal\labdoo_dootrip\Service\Queue\Feeder\QueueFeederInterface;
use Drupal\labdoo_dootrip\Service\Queue\Feeder\TotalCo2SavingsQueueFeeder;
use Drupal\labdoo_dootrip\Service\Repository\DootripRepository;
use Drupal\node\Entity\Node;
use Drupal\queue_manager\Service\QueueHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests feeder IDs, envelopes and storage status mapping.
 */
#[CoversClass(AbstractQueueFeeder::class)]
#[CoversClass(TotalCo2SavingsQueueFeeder::class)]
#[CoversClass(DootripCapacityQueueFeeder::class)]
#[CoversClass(DootripRepository::class)]
#[Group('labdoo_queues')]
class DootripQueueContractTest extends UnitTestCase {

  /**
   * The base must require a concrete feeder with a non-null queue identity.
   */
  public function testRequiredFeederContract(): void {
    $base = new \ReflectionClass(AbstractQueueFeeder::class);
    $this->assertTrue($base->isAbstract());
    $this->assertTrue($base->implementsInterface(QueueFeederInterface::class));
    $type = (new \ReflectionMethod(QueueFeederInterface::class, 'getQueueId'))->getReturnType();
    $this->assertInstanceOf(\ReflectionNamedType::class, $type);
    $this->assertSame('string', $type->getName());
    $this->assertFalse($type->allowsNull());
    $helper = $this->createMock(QueueHelper::class);
    $this->assertSame(TotalCo2SavingsQueueFeeder::QUEUE_ID, (new TotalCo2SavingsQueueFeeder($helper))->getQueueId());
    $this->assertSame(DootripCapacityQueueFeeder::QUEUE_ID, (new DootripCapacityQueueFeeder($helper))->getQueueId());
  }

  /**
   * Provides successful cleanup and a transient cleanup failure.
   */
  public static function cleanupModes(): array {
    return [[FALSE], [TRUE]];
  }

  /**
   * Cleanup failures must not prevent a fresh global recompute item.
   */
  #[DataProvider('cleanupModes')]
  public function testGlobalEnvelopeSurvivesCleanupFailure(bool $failure): void {
    $helper = $this->createMock(QueueHelper::class);
    $search = $helper->expects($this->once())->method('searchItems')->with(TotalCo2SavingsQueueFeeder::QUEUE_ID, []);
    if ($failure) {
      $search->willThrowException(new \RuntimeException('Transient fixture cleanup failure'));
      $helper->expects($this->never())->method('deleteItem');
    }
    else {
      $search->willReturn([(object) ['item_id' => '10']]);
      $helper->expects($this->once())->method('deleteItem')->with(TotalCo2SavingsQueueFeeder::QUEUE_ID, '10');
    }
    $helper->expects($this->once())->method('enqueueData')->willReturnCallback(function (string $id, array $data) {
      $this->assertSame(TotalCo2SavingsQueueFeeder::QUEUE_ID, $id);
      $this->assertSame($id, $data['queue_id']);
      $this->assertInstanceOf(\DateTime::class, $data['timestamp']);
      $this->assertSame([901], $data['data']);
      return NULL;
    });
    $node = $this->createMock(Node::class);
    $node->method('id')->willReturn(901);
    (new TotalCo2SavingsQueueFeeder($helper))->feedQueue($node);
  }

  /**
   * Repeated capacity payloads must remain identical for deduplication.
   */
  public function testCapacityEnvelopeRemainsStable(): void {
    $helper = $this->createMock(QueueHelper::class);
    $payloads = [];
    $helper->expects($this->exactly(2))->method('enqueueData')->willReturnCallback(static function (string $id, array $data) use (&$payloads) {
      $payloads[] = [$id, $data];
      return NULL;
    });
    $node = $this->createMock(Node::class);
    $node->method('id')->willReturn(901);
    $node->method('getOwnerId')->willReturn(17);
    $feeder = new DootripCapacityQueueFeeder($helper);
    $feeder->feedQueue($node);
    $feeder->feedQueue($node);
    $this->assertSame($payloads[0], $payloads[1]);
    $this->assertSame([
      'queue_id' => DootripCapacityQueueFeeder::QUEUE_ID,
      'timestamp' => NULL,
      'data' => ['id' => 901, 'uid' => 17],
    ], $payloads[0][1]);
  }

  /**
   * Provides Drupal storage status integers and their boolean API results.
   */
  public static function saveStatuses(): array {
    return [[1, TRUE], [2, TRUE], [0, FALSE]];
  }

  /**
   * Builds a repository without a site connection or real entity writes.
   */
  private function repository(): DootripRepository {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    return new DootripRepository($this->createMock(EntityTypeManagerInterface::class), $factory, $this->createMock(Connection::class));
  }

  /**
   * Created and updated status values must both report successful persistence.
   */
  #[DataProvider('saveStatuses')]
  public function testSaveStatus(int $status, bool $expected): void {
    $node = $this->createMock(Node::class);
    $node->expects($this->once())->method('save')->willReturn($status);
    $this->assertSame($expected, $this->repository()->saveEntity($node));
  }

  /**
   * Storage exceptions must still be reported as FALSE to queue consumers.
   */
  public function testSaveFailure(): void {
    $node = $this->createMock(Node::class);
    $node->method('save')->willThrowException(new EntityStorageException('Injected storage failure'));
    $this->assertFalse($this->repository()->saveEntity($node));
  }

}
