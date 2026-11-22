<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_statistics\Controller\AdvancedStatisticsController;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests platform age using the primary timestamp and the content fallback.
 */
#[CoversClass(AdvancedStatisticsController::class)]
#[Group('labdoo_statistics')]
class PlatformUptimeTest extends UnitTestCase {

  /**
   * Provides primary-user and node-aggregate lookup results.
   */
  public static function timestamps(): array {
    return [
      'primary user' => [TRUE, TRUE],
      'missing user falls back' => [FALSE, TRUE],
      'no user or content' => [FALSE, FALSE],
    ];
  }

  /**
   * Aggregate expressions return aliases, so execution must use the query.
   */
  #[DataProvider('timestamps')]
  public function testUptime(bool $hasUserDate, bool $hasContentDate): void {
    $timestamp = time() - 400 * 86400;
    $database = $this->createMock(Connection::class);
    $userQuery = $this->createMock(SelectInterface::class);
    $userQuery->expects($this->once())->method('fields')->with('u', ['created'])->willReturnSelf();
    $userQuery->expects($this->once())->method('condition')->with('uid', 1)->willReturnSelf();
    $userResult = $this->createMock(StatementInterface::class);
    $userResult->method('fetchField')->willReturn($hasUserDate ? (string) $timestamp : FALSE);
    $userQuery->expects($this->once())->method('execute')->willReturn($userResult);
    $nodeQuery = $this->createMock(SelectInterface::class);
    if (!$hasUserDate) {
      $nodeQuery->expects($this->once())->method('addExpression')->with('MIN(created)')->willReturn('expression');
      $nodeResult = $this->createMock(StatementInterface::class);
      $nodeResult->method('fetchField')->willReturn($hasContentDate ? (string) $timestamp : NULL);
      $nodeQuery->expects($this->once())->method('execute')->willReturn($nodeResult);
    }
    else {
      $nodeQuery->expects($this->never())->method('execute');
    }
    $database->expects($this->exactly($hasUserDate ? 1 : 2))->method('select')->willReturnCallback(
      static fn ($table) => match ($table) {
        'users_field_data' => $userQuery,
        'node_field_data' => $nodeQuery,
        default => throw new \LogicException('Unexpected uptime table.'),
      }
    );
    $controller = new AdvancedStatisticsController($database, $this->createMock(CommonRepository::class), $this->createMock(CountryManagerInterface::class), $this->createMock(DateFormatterInterface::class));
    $controller->setStringTranslation($this->getStringTranslationStub());
    $result = (new \ReflectionMethod($controller, 'getPlatformUptime'))->invoke($controller);
    if ($hasUserDate || $hasContentDate) {
      $this->assertIsArray($result);
      $this->assertSame(400, $result['total_days']);
      $this->assertSame(1, $result['years']);
    }
    else {
      $this->assertInstanceOf(TranslatableMarkup::class, $result);
      $this->assertSame('Unknown', (string) $result);
    }
  }

}
