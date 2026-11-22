<?php

declare(strict_types=1);

namespace Drupal\labdoo_map\Service;

use Drupal\views\Plugin\views\query\Sql;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\views\Views;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Retrieves the complete accessible, filtered map dataset with tagged caching.
 */
final class MapPointRepository {

  private const VIEWS = [
    'dootronic' => 'dootronics_dashboard',
    'dootrip' => 'dootrips_dashboard',
    'edoovillage' => 'edoovillages',
    'hub' => 'hubs_dashboard',
    'labdooer' => 'labdoers',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly CacheContextsManager $contexts,
    private readonly LanguageManagerInterface $languages,
    private readonly AccountProxyInterface $account,
  ) {}

  /**
   * Returns map points and the metadata needed by their JSON response.
   */
  public function getPoints(string $type, array $filters): array {
    $metadata = (new CacheableMetadata())
      ->setCacheContexts([
        'user',
        'user.permissions',
        'user.node_grants:view',
        'languages:language_content',
        'languages:language_interface',
      ])
      ->setCacheMaxAge(300);
    if (!isset(self::VIEWS[$type])) {
      return ['points' => [], 'cache' => $metadata];
    }
    $view = Views::getView(self::VIEWS[$type]);
    if (!$view || !$view->access('page_1', $this->account)) {
      throw new AccessDeniedHttpException();
    }
    $view->setDisplay('page_1');
    $metadata->addCacheableDependency($view->storage);
    $metadata->addCacheTags([$type === 'labdooer' ? 'user_list' : 'node_list', 'user:' . $this->account->id()]);
    // Presentation controls must not duplicate the cached source dataset.
    foreach (['_map', 'zoom', 'bbox', 'page', 'order', 'sort', 'theme', 'palette'] as $key) {
      unset($filters[$key]);
    }
    $this->sortKeys($filters);
    $contextKeys = $this->contexts->convertTokensToKeys($metadata->getCacheContexts());
    $metadata = $metadata->merge($contextKeys);
    $cid = 'labdoo_map:points:' . hash('sha256', serialize([$type, $filters, $contextKeys->getKeys()]));
    if ($metadata->getCacheMaxAge() !== 0 && ($cached = $this->cache->get($cid))) {
      return ['points' => $cached->data, 'cache' => $metadata];
    }
    $view->setExposedInput($filters);
    $view->setItemsPerPage(0);
    $view->build();
    if (!$view->query instanceof Sql) {
      throw new \UnexpectedValueException('Map points require a SQL-backed dashboard view.');
    }
    // Keep the actual dashboard joins, filters and access conditions. Selecting
    // IDs from a derived query avoids materializing a large PHP IN list.
    $isUser = $type === 'labdooer';
    $id = $isUser ? 'uid' : 'nid';
    // Aggregated Views can rename the base ID (for example, nid_1). Request
    // an explicit alias rather than assuming the display's selected alias.
    $idAlias = $view->query->addField($view->storage->get('base_table'), $id, 'labdoo_map_entity_id');
    $viewQuery = $view->query->query();
    $ids = $this->database->select($viewQuery, 'filtered')->fields('filtered', [$idAlias]);
    $field = in_array($type, ['hub', 'dootrip', 'labdooer'], TRUE) ? 'field_locations' : 'field_location';
    $table = $isUser ? 'users_field_data' : 'node_field_data';
    $query = $this->database->select($table, 'entity');
    $query->join(($isUser ? 'user__' : 'node__') . $field, 'location', 'entity.' . $id . ' = location.entity_id AND location.deleted = 0');
    $query->addField('entity', $id, 'id');
    if ($isUser) {
      $query->addField('entity', 'name', 'title');
    }
    else {
      $langcode = $this->languages->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
      $query->leftJoin('node_field_data', 'translation', 'translation.nid = entity.nid AND translation.langcode = :map_language AND translation.status = 1', [
        ':map_language' => $langcode,
      ]);
      $query->addExpression('COALESCE(translation.title, entity.title)', 'title');
      $query->condition('entity.default_langcode', 1);
      $query->condition('entity.type', $type);
      $query->addTag('node_access');
      $query->addMetaData('base_table', 'node_field_data');
      $query->addMetaData('account', $this->account);
    }
    $lat = 'location.' . $field . '_lat';
    $lon = 'location.' . $field . '_lon';
    $query->addField('location', $field . '_lat', 'lat');
    $query->addField('location', $field . '_lon', 'lon');
    $query->condition('entity.' . $id, $ids, 'IN');
    $query->condition('entity.status', 1);
    $query->condition($lat, [-56.7, 77.75], 'BETWEEN');
    $query->condition($lon, [-180, 180], 'BETWEEN');
    $query->condition($query->orConditionGroup()->condition($lat, 0, '!=')->condition($lon, 0, '!='));
    foreach ([['40.416775', '-3.703790'], ['41.385064', '2.173404']] as [$defaultLat, $defaultLon]) {
      $query->condition($query->orConditionGroup()->condition($lat, $defaultLat, '!=')->condition($lon, $defaultLon, '!='));
    }
    $query->distinct();
    $points = [];
    foreach ($query->execute() as $row) {
      $points[] = [
        'lat' => (float) $row->lat,
        'lon' => (float) $row->lon,
        'id' => (int) $row->id,
        'title' => $row->title,
      ];
    }
    if ($metadata->getCacheMaxAge() !== 0) {
      $this->cache->set($cid, $points, time() + $metadata->getCacheMaxAge(), $metadata->getCacheTags());
    }
    return ['points' => $points, 'cache' => $metadata];
  }

  /**
   * Normalizes equivalent exposed-filter arrays before generating a cache key.
   */
  private function sortKeys(array &$values): void {
    ksort($values);
    foreach ($values as &$value) {
      if (is_array($value)) {
        $this->sortKeys($value);
      }
    }
  }

}
