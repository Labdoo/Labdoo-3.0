<?php

/**
 * @file
 * Verifies historical chart totals using connection-local temporary fixtures.
 */

declare(strict_types=1);

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\labdoo_statistics\Controller\AdvancedStatisticsController;
use Drupal\mysql\Driver\Database\mysql\Connection as MysqlConnection;

require __DIR__ . '/assert-isolated.php';

$database = \Drupal::database();
// Temporary tables disappear with this connection; no site records are changed.
$database->query('CREATE TEMPORARY TABLE statistics_history_fixture (nid INT, entity_id INT, type VARCHAR(32), created BIGINT, field_distance_dootrip_value DOUBLE)');
$mapping = [
  'getDootronicsEvolutionData' => 'dootronic',
  'getEdoovillageEvolutionData' => 'edoovillage',
  'getHubEvolutionData' => 'hub',
  'getDootripEvolutionData' => 'dootrip',
  'getTeamPostEvolutionData' => 'team_post',
  'getNodeEvolutionData' => 'page',
  'getCommentEvolutionData' => 'comment',
  'getWikiActivityData' => 'wiki',
];
$connection = new class($database) extends MysqlConnection {

  /**
   * Wraps the isolated connection without opening another connection.
   */
  public function __construct(private Connection $scope) {}

  /**
   * {@inheritdoc}
   */
  public function select($table, $alias = NULL, array $options = []) {
    return new class($this->scope, 'statistics_history_fixture', $alias, $options) extends Select {

      /**
       * Keeps joined history inside the same temporary fixture.
       */
      public function addJoin($type, $table, $alias = NULL, $condition = NULL, $arguments = []) {
        return parent::addJoin($type, $table === 'node_field_data' ? 'statistics_history_join_fixture' : $table, $alias, $condition, $arguments);
      }

    };
  }

};
$controller = new AdvancedStatisticsController($connection, \Drupal::service('labdoo_common.repository.common'), \Drupal::service('country_manager'), \Drupal::service('date.formatter'));
$passed = [];
try {
  foreach ($mapping as $method => $bundle) {
    $database->query('DELETE FROM statistics_history_fixture');
    // Two records predate the window, including a month boundary at midnight.
    $dates = [
      '2023-06-01 00:00:00',
      '2024-12-31 23:59:59',
      '2025-02-01 00:00:00',
      '2025-02-28 23:59:59',
      '2025-12-15 12:00:00',
    ];
    foreach ($dates as $index => $date) {
      $database->insert('statistics_history_fixture')->fields([
        'nid' => $index + 1,
        'entity_id' => $index + 1,
        'type' => $bundle,
        'created' => strtotime($date),
        'field_distance_dootrip_value' => 0,
      ])->execute();
    }
    $result = (new ReflectionMethod($controller, $method))->invoke($controller);
    $expected = [2, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5];
    if ($result['values'] !== $expected || count($result['labels']) !== 12) {
      throw new RuntimeException($method . ': historical baseline, boundaries or inactive months failed: ' . json_encode($result['values']));
    }
    $database->query('DELETE FROM statistics_history_fixture');
    $empty = (new ReflectionMethod($controller, $method))->invoke($controller);
    if ($empty['values'] !== array_fill(0, 12, 0)) {
      throw new RuntimeException($method . ': empty history must return twelve zeros.');
    }
    $passed[] = $method;
  }
  $year = (int) date('Y');
  foreach ([$year - 6 => 10.5, $year - 4 => 2.25, $year - 2 => 3.75, $year => 4.0] as $dateYear => $distance) {
    $database->insert('statistics_history_fixture')->fields([
      'nid' => $dateYear,
      'entity_id' => $dateYear,
      'type' => 'dootrip',
      'created' => mktime(0, 0, 0, 1, 1, $dateYear),
      'field_distance_dootrip_value' => $distance,
    ])->execute();
  }
  $database->query('CREATE TEMPORARY TABLE statistics_history_join_fixture AS SELECT * FROM statistics_history_fixture');
  $result = (new ReflectionMethod($controller, 'getDootripKmData'))->invoke($controller);
  if ($result['evolution']['values'] !== [12.75, 12.75, 16.5, 16.5, 20.5]) {
    throw new RuntimeException('Annual distance must include history before the visible years.');
  }
  $passed[] = 'getDootripKmData';
}
finally {
  $database->query('DROP TEMPORARY TABLE IF EXISTS statistics_history_join_fixture');
  $database->query('DROP TEMPORARY TABLE statistics_history_fixture');
}
echo 'CUMULATIVE_JSON:' . json_encode(['passed' => count($passed), 'cases' => $passed], JSON_THROW_ON_ERROR);
