<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Drupal\labdoo_dootrip\Plugin\QueueWorker\DootripCapacityQueueWorker;
use Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface;
use Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface;
use Drupal\labdoo_dootronics\Plugin\QueueWorker\DootronicRecomputeQueueWorker;
use Drupal\labdoo_dootronics\Service\Compute\DootronicComputeInterface;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests shared retry, cache and legacy-payload contracts of recompute workers.
 */
#[CoversClass(DootronicRecomputeQueueWorker::class)]
#[CoversClass(DootripCapacityQueueWorker::class)]
#[Group('labdoo_queues')]
class RecomputeQueueWorkerTest extends UnitTestCase {

  /**
   * Provides the two reciprocal recompute workers.
   */
  public static function workers(): array {
    return [['dootronic'], ['dootrip']];
  }

  /**
   * Provides current and historical payload shapes for both workers.
   */
  public static function payloads(): array {
    $cases = [];
    foreach (['dootronic', 'dootrip'] as $kind) {
      foreach (['structured', 'scalar', 'legacy_array'] as $shape) {
        $cases[$kind . ' ' . $shape] = [$kind, $shape];
      }
    }
    return $cases;
  }

  /**
   * Builds a worker with controlled storage, computations and cache events.
   *
   * @param string $kind
   *   The entity bundle processed by the worker.
   * @param list<bool> $saveOutcomes
   *   Ordered storage results for successive processing attempts.
   * @param int[] $originalIds
   *   Previously related entities carried by the payload.
   * @param bool $nodePresent
   *   Whether repository loading returns the fixture entity.
   * @param int $loads
   *   The expected number of processing attempts that load the entity.
   *
   * @return array
   *   The worker and its observable fixture state.
   */
  private function worker(string $kind, array $saveOutcomes, array $originalIds = [], bool $nodePresent = TRUE, int $loads = 1): array {
    $state = (object) ['events' => [], 'flags' => []];
    $node = $this->createMock(Node::class);
    $node->method('id')->willReturn(901);
    $node->method('getOwnerId')->willReturn(17);
    $node->method('__set')->willReturnCallback(static function ($name, $value) use ($state): void {
      $state->flags[$name] = $value;
    });
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $factory);
    \Drupal::setContainer($container);
    $dispatcher = new EventDispatcher();
    $dispatcher->addListener(InvalidateCacheTagsEvent::EVENT_NAME, static function (InvalidateCacheTagsEvent $event) use ($state): void {
      $state->events[] = $event->getCacheTags();
    });
    $isDevice = $kind === 'dootronic';
    $repository = $this->createMock($isDevice ? DootronicRepositoryInterface::class : DootripRepositoryInterface::class);
    $repository->expects($this->exactly($loads))->method('load')->with(901)->willReturn($nodePresent ? $node : NULL);
    $save = $repository->expects($this->exactly(count($saveOutcomes)))->method('saveEntity');
    if ($saveOutcomes) {
      $save->with($node)->willReturnOnConsecutiveCalls(...$saveOutcomes);
    }
    $compute = $this->createMock($isDevice ? DootronicComputeInterface::class : DootripComputeInterface::class);
    $methods = $isDevice
      ? ['computeEdooVillageData', 'computeHubData', 'computeRelatedDootrips']
      : ['computeDootripCapacity', 'computeRelatedDootronics', 'computeEdoovillagesAssigned'];
    foreach ($methods as $method) {
      $expectation = $compute->expects($this->exactly($nodePresent ? $loads : 0))->method($method);
      if (str_starts_with($method, 'computeRelated')) {
        $expectation->with($node, $originalIds);
      }
      else {
        $expectation->with($node);
      }
    }
    $class = $isDevice ? DootronicRecomputeQueueWorker::class : DootripCapacityQueueWorker::class;
    $worker = new $class([], 'test_' . $kind, [], $factory, $compute, $repository, $dispatcher);
    return [$worker, $state];
  }

  /**
   * A rejected save must propagate; replay succeeds and invalidates once.
   */
  #[DataProvider('workers')]
  public function testFailedSaveCanBeRetried(string $kind): void {
    [$worker, $state] = $this->worker($kind, [FALSE, TRUE], loads: 2);
    $envelope = ['queue_id' => 'test', 'timestamp' => NULL, 'data' => ['id' => 901, 'uid' => 17]];
    $failure = NULL;
    try {
      $worker->processItem($envelope);
    }
    catch (\RuntimeException $error) {
      $failure = $error;
    }
    $this->assertNotNull($failure, 'Rejected storage was reported as successful queue processing.');
    $this->assertStringContainsString('save', $failure->getMessage());
    $this->assertSame([], $state->events);
    $worker->processItem($envelope);
    $this->assertCount(1, $state->events);
    $this->assertSame(['skip_geocoding_enqueue' => TRUE, 'skip_recompute_enqueue' => TRUE], $state->flags);
  }

  /**
   * Valid current and historical envelopes retain calculations and cache tags.
   */
  #[DataProvider('payloads')]
  public function testSuccessfulSave(string $kind, string $shape): void {
    $originalIds = $shape === 'structured' ? [12, 13] : [];
    [$worker, $state] = $this->worker($kind, [TRUE], $originalIds);
    $key = $kind === 'dootronic' ? 'original_dootrip_ids' : 'original_dootronic_ids';
    $data = match ($shape) {
      'structured' => ['id' => 901, 'uid' => 47, $key => $originalIds],
      'scalar' => 901,
      'legacy_array' => [901],
      default => throw new \InvalidArgumentException('Unknown payload fixture shape.'),
    };
    $worker->processItem(['queue_id' => 'test', 'timestamp' => NULL, 'data' => $data]);
    $this->assertSame([[
      $kind === 'dootronic' ? 'dootronics_chart' : 'dootrip_chart',
      'node:' . $kind,
      $kind . ':901:17',
    ]], $state->events);
    $this->assertSame(['skip_geocoding_enqueue' => TRUE, 'skip_recompute_enqueue' => TRUE], $state->flags);
  }

  /**
   * Deleted entities remain terminal and clear the former owner's cache.
   */
  #[DataProvider('workers')]
  public function testDeletedEntity(string $kind): void {
    [$worker, $state] = $this->worker($kind, [], [], FALSE);
    $worker->processItem(['queue_id' => 'test', 'timestamp' => NULL, 'data' => ['id' => 901, 'uid' => 47]]);
    $this->assertSame([[
      $kind === 'dootronic' ? 'dootronics_chart' : 'dootrip_chart',
      'node:' . $kind,
      $kind . ':901:47',
    ]], $state->events);
    $this->assertSame([], $state->flags);
  }

  /**
   * Empty and corrupt envelopes must remain terminal without storage calls.
   */
  #[DataProvider('workers')]
  public function testTerminalPayloads(string $kind): void {
    [$worker, $state] = $this->worker($kind, [], [], FALSE, 0);
    $worker->processItem([]);
    $worker->processItem(['unknown' => []]);
    $this->assertSame([], $state->events);
  }

}
