<?php

declare(strict_types=1);

/**
 * @file
 * Checks lossless clustering and viewport selection without a Drupal database.
 */

require dirname(__DIR__, 2) . '/web/modules/custom/labdoo_map/src/Service/MapPointClusterer.php';

use Drupal\labdoo_map\Service\MapPointClusterer;

$expect = static function (bool $condition, string $message): void {
  if (!$condition) { throw new RuntimeException($message); }
};
$clusterer = new MapPointClusterer();
$points = [];
for ($id = 1; $id <= 2000; $id++) {
  $points[] = ['id' => $id, 'lat' => 40 + $id / 100000, 'lon' => -3, 'title' => 'Test ' . $id];
}
$world = $clusterer->cluster($points, 2);
$expect(array_sum(array_column($world['points'], 'count')) === 2000, 'World clusters lost device counts.');
$expect($world['total'] === 2000 && $world['visible'] === 2000, 'World totals are incorrect.');
$expect(strlen(json_encode($world)) < strlen(json_encode($points)) / 20, 'World representation remains too large.');
$detail = $clusterer->cluster($points, 14, [-4, 39, -2, 41]);
$expect(array_column($detail['points'], 'id') === range(1, 2000), 'Detail dropped or reordered individual devices.');
$expect($clusterer->cluster($points, 14, [10, 10, 11, 11])['visible'] === 0, 'Viewport filtering included outside locations.');
$dateline = [
  ['id' => 1, 'lat' => 0, 'lon' => 179, 'title' => 'East'],
  ['id' => 2, 'lat' => 0, 'lon' => -179, 'title' => 'West'],
  ['id' => 3, 'lat' => 0, 'lon' => 0, 'title' => 'Other'],
];
$crossing = $clusterer->cluster($dateline, 14, [170, -1, -170, 1]);
$expect(array_column($crossing['points'], 'id') === [1, 2], 'Dateline-crossing bounds lost one hemisphere.');
$expect($clusterer->cluster([], 2)['bounds'] === NULL, 'An empty dataset must have no bounds.');
echo "Map clustering, individual counts, equator and antimeridian checks passed.\n";
