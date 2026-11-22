<?php

/**
 * @file
 * Exercises uptime fallback queries against the isolated clone without writes.
 */

declare(strict_types=1);

use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\labdoo_statistics\Controller\AdvancedStatisticsController;
use Drupal\mysql\Driver\Database\mysql\Connection as MysqlConnection;

require __DIR__ . '/assert-isolated.php';

$database = \Drupal::database();
$query = $database->select('node_field_data', 'n');
$query->addExpression('MIN(created)');
$oldest = (int) $query->execute()->fetchField();
if (!$oldest) {
  throw new RuntimeException('The statistics fixture requires existing content.');
}
$passed = [];
foreach ([FALSE, TRUE] as $emptyContent) {
  // Suppress rows only in these read queries, leaving all stored data intact.
  $connection = new class($database, $emptyContent) extends MysqlConnection {

    /**
     * Builds a read-only query scope around the real database connection.
     */
    public function __construct(private Connection $scope, private bool $emptyContent) {}

    /**
     * {@inheritdoc}
     */
    public function select($table, $alias = NULL, array $options = []) {
      $query = $this->scope->select($table, $alias, $options);
      if ($table === 'users_field_data' || ($table === 'node_field_data' && $this->emptyContent)) {
        $query->where('1 = 0');
      }
      return $query;
    }

  };
  $controller = new AdvancedStatisticsController($connection, \Drupal::service('labdoo_common.repository.common'), \Drupal::service('country_manager'), \Drupal::service('date.formatter'));
  $value = (new ReflectionMethod($controller, 'getPlatformUptime'))->invoke($controller);
  if ($emptyContent) {
    if (!$value instanceof TranslatableMarkup || $value->getUntranslatedString() !== 'Unknown') {
      throw new RuntimeException('Missing timestamps must produce the translated unknown result.');
    }
    $passed[] = 'empty aggregate returns Unknown';
  }
  else {
    $date = (new DateTime())->setTimestamp($oldest);
    $days = $date->diff(new DateTime())->days;
    if (!is_array($value) || $value['total_days'] !== $days) {
      throw new RuntimeException('Fallback did not use the oldest content timestamp.');
    }
    $passed[] = 'oldest content timestamp computes uptime';
  }
}
echo 'UPTIME_JSON:' . json_encode(['passed' => count($passed), 'cases' => $passed], JSON_THROW_ON_ERROR);
