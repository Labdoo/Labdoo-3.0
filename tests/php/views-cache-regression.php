<?php

/**
 * @file
 * Verify Views preserves cache invalidation when relationships are missing.
 *
 * Run with: php -d xdebug.mode=off tests/php/views-cache-regression.php.
 */

$root = dirname(__DIR__, 2);
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('Drupal\\views\\', $root . '/web/core/modules/views/src');

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\views\Plugin\views\query\Sql;

$query = new class() extends Sql {

  /**
   * Entities returned by the SQL query, including absent relationships.
   *
   * @var array
   */
  public array $entities = [];

  /**
   * Skip unrelated query services in this focused regression test.
   */
  public function __construct() {
  }

  /**
   * {@inheritdoc}
   */
  protected function getAllEntities() {
    return $this->entities;
  }

};

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

$query->entities = [
  (new CacheableMetadata())->setCacheTags(['node:17'])->setCacheMaxAge(600),
  NULL,
  (new CacheableMetadata())->setCacheTags(['node:23'])->setCacheMaxAge(300),
];
$tags = $query->getCacheTags();
sort($tags);
$check($tags === ['node:17', 'node:23'], 'Missing relationships must not discard valid entity cache tags.');
$check($query->getCacheMaxAge() === 300, 'The shortest entity cache lifetime must be preserved.');

$query->entities[] = (new CacheableMetadata())->setCacheMaxAge(0);
$check($query->getCacheMaxAge() === 0, 'An uncacheable entity must keep the query uncacheable.');

$query->entities = [NULL];
$check($query->getCacheTags() === [], 'Missing relationships must be safe.');
$check($query->getCacheMaxAge() === Cache::PERMANENT, 'An empty query retains the default lifetime.');

echo "Views cache regression: 5 assertions passed.\n";
