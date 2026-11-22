<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\labdoo_common\Routing\RouteSubscriber;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Drupal\labdoo_common\Controller\RedisReportController;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Client;
use Predis\Command\CommandInterface;
use Predis\Connection\ConnectionInterface;
use Predis\Response\Error;
use Predis\Response\ServerException;

/**
 * A cache user's report must work without Redis configuration privileges.
 */
class RedisReportControllerTest extends UnitTestCase {

  /**
   * Supplies flat and sectioned INFO protocol responses.
   */
  public static function formats(): array {
    return [['flat'], ['sectioned']];
  }

  /**
   * Both INFO formats contain memory policy without any CONFIG GET request.
   */
  #[DataProvider('formats')]
  public function testInfoWithoutConfigurationAccess(string $format): void {
    $payload = ($format === 'sectioned' ? "# Server\r\n" : '') . "redis_version:7.0.0\r\nredis_mode:standalone\r\nuptime_in_seconds:120\r\n";
    $payload .= ($format === 'sectioned' ? "# Clients\r\n" : '') . "connected_clients:1\r\n";
    $payload .= ($format === 'sectioned' ? "# Memory\r\n" : '') . "used_memory:1024\r\nused_memory_human:1K\r\nmaxmemory:1048576\r\nmaxmemory_policy:allkeys-lru\r\n";
    $connection = $this->createMock(ConnectionInterface::class);
    $connection->expects($this->exactly(2))->method('executeCommand')->willReturnCallback(static function (CommandInterface $command) use ($payload) {
      return match ($command->getId()) {
        'INFO' => $payload,
        'DBSIZE' => 7,
        default => throw new \RuntimeException('Unexpected Redis command: ' . $command->getId()),
      };
    });
    $controller = (new \ReflectionClass(RedisReportController::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty($controller, 'redis'))->setValue($controller, new Client($connection));
    $info = (new \ReflectionMethod($controller, 'info'))->invoke($controller);
    $this->assertSame('7.0.0', $info['redis_version']);
    $this->assertSame(7, (int) $info['db_size']);
    $this->assertSame(1048576, (int) $info['maxmemory']);
    $this->assertSame('allkeys-lru', $info['maxmemory_policy']);
  }

  /**
   * A denied INFO request must stay an error rather than report fake metrics.
   */
  public function testInfoFailuresPropagate(): void {
    $connection = $this->createMock(ConnectionInterface::class);
    $connection->method('executeCommand')->willReturn(new Error('NOPERM INFO denied'));
    $controller = (new \ReflectionClass(RedisReportController::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty($controller, 'redis'))->setValue($controller, new Client($connection));
    $this->expectException(ServerException::class);
    (new \ReflectionMethod($controller, 'info'))->invoke($controller);
  }

  /**
   * The report override keeps Redis's existing access requirement intact.
   */
  public function testRouteKeepsAccessRequirement(): void {
    $collection = new RouteCollection();
    $route = new Route('/admin/reports/redis', [], ['_permission' => 'access redis report']);
    $collection->add('redis.report', $route);
    $subscriber = new RouteSubscriber();
    (new \ReflectionMethod($subscriber, 'alterRoutes'))->invoke($subscriber, $collection);
    $this->assertSame(RedisReportController::class . '::overview', $route->getDefault('_controller'));
    $this->assertSame('access redis report', $route->getRequirement('_permission'));
  }

}
