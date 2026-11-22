<?php

declare(strict_types=1);

namespace Drupal\Tests\labdoo_migrate\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\labdoo_migrate\Services\Incremental\UserDependencyImporter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests restoration of caller connection state when metadata reads fail.
 */
#[CoversClass(UserDependencyImporter::class)]
#[Group('labdoo_migrate')]
class UserDependencyImporterMetadataTest extends UnitTestCase {

  /**
   * Provides a database failure and an incomplete metadata result.
   */
  public static function metadataFailures(): array {
    return [
      'read failure' => [TRUE, 'Injected metadata failure'],
      'missing metadata' => [FALSE, 'Cannot inspect destination node sequence metadata.'],
    ];
  }

  /**
   * Failure must abort the guard and restore the exact prior session value.
   */
  #[DataProvider('metadataFailures')]
  public function testMetadataFailureRestoresStatistics(bool $queryFails, string $message): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn(['node_count' => 0]);
    $statement->method('fetchField')->willReturn('86400');
    $statement->method('fetchAll')->willReturn([]);
    $connection = $this->createMock(Connection::class);
    $connection->method('getConnectionOptions')->willReturn(['database' => 'fixture']);
    $connection->method('getPrefix')->willReturn('fixture_');
    $settings = [];
    $connection->method('query')->willReturnCallback(static function (string $query, array $args = []) use ($statement, $queryFails, &$settings) {
      if ($query === 'SET SESSION information_schema_stats_expiry = 0') {
        $settings[] = ['disable', $args];
      }
      elseif ($query === 'SET SESSION information_schema_stats_expiry = CAST(:expiry AS UNSIGNED)') {
        $settings[] = ['restore', $args];
      }
      elseif ($queryFails && str_starts_with($query, 'SELECT TABLE_NAME,AUTO_INCREMENT')) {
        throw new \RuntimeException('Injected metadata failure');
      }
      return $statement;
    });

    // Exercise the private read guard without bootstrapping a destination site.
    $reflection = new \ReflectionClass(UserDependencyImporter::class);
    $importer = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('destination')->setValue($importer, $connection);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage($message);
    try {
      $reflection->getMethod('nodeInvariants')->invoke($importer);
    }
    finally {
      $this->assertSame([
        ['disable', []],
        ['restore', [':expiry' => '86400']],
      ], $settings);
    }
  }

}
