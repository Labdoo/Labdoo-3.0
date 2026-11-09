<?php

/**
 * @file
 * Queue regression checks with in-memory entities and mocked HTTP/geocoders.
 *
 * Run with Drush php:script on an isolated labdoo_e2e_* database only.
 * No nodes, revisions or remote geocoding requests are created by this test.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\State;
use Drupal\geocoder\GeocoderInterface;
use Drupal\labdoo_edoovillage\Plugin\QueueWorker\EdooVillageGeocoding;
use Drupal\labdoo_edoovillage\Service\Compute\EdooVillageComputeInterface;
use Drupal\node\Entity\Node;
use Geocoder\Model\Address;
use Geocoder\Model\AddressCollection;
use Geocoder\Model\AdminLevelCollection;
use Geocoder\Model\Country;
use Geocoder\Query\GeocodeQuery;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

if (!Settings::get('labdoo_e2e') || !str_starts_with(Database::getConnection()->getConnectionOptions()['database'], 'labdoo_e2e_')) {
  throw new RuntimeException('An isolated E2E database is required.');
}

// Test the worktree's current implementation, without copying it over the site.
$workerSource = dirname(__DIR__) . '/web/modules/custom/labdoo_edoovillage/src/Plugin/QueueWorker/EdooVillageGeocoding.php';
if (class_exists(EdooVillageGeocoding::class, FALSE)) {
  if (realpath((new ReflectionClass(EdooVillageGeocoding::class))->getFileName()) !== realpath($workerSource)) {
    throw new RuntimeException('The worker was already loaded from another checkout.');
  }
}
else {
  require_once $workerSource;
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new LogicException($message);
  }
};

$nodeFactory = static function (array $overrides = [], string $bundle = 'edoovillage') {
  return new class($overrides, $bundle) extends Node {
    public int $saves = 0;
    public bool $skip_geocoding_enqueue = FALSE;
    public array $testFields;
    private string $testBundle;

    public function __construct(array $overrides, string $bundle) {
      $this->testBundle = $bundle;
      $this->testFields = array_replace([
        'field_country' => '',
        'field_city' => '',
        'field_location' => ['lat' => 40.4168, 'lon' => -3.7038],
        'title' => 'Existing title',
      ], $overrides);
    }

    public function bundle() {
      return $this->testBundle;
    }

    public function hasField($field_name) {
      return array_key_exists($field_name, $this->testFields);
    }

    public function get($field_name) {
      return new class($this->testFields[$field_name] ?? NULL) {
        public function __construct(public mixed $value) {}

        public function isEmpty(): bool {
          return $this->value === NULL || $this->value === '' || $this->value === [];
        }

        public function first(): static {
          return $this;
        }

        public function getValue(): mixed {
          return $this->value;
        }
      };
    }

    public function set($name, $value, $notify = TRUE) {
      $this->testFields[$name] = $value;
      return $this;
    }

    public function save() {
      if (!$this->skip_geocoding_enqueue) {
        throw new LogicException('A successful save must suppress geocoding re-enqueue.');
      }
      $this->saves++;
      return 2;
    }
  };
};

$storage = new class {
  public array $nodes = [];
  public int $loads = 0;

  public function load($nid) {
    $this->loads++;
    return $this->nodes[$nid] ?? NULL;
  }
};
$manager = new class($storage) extends EntityTypeManager {
  public function __construct(private object $testStorage) {}

  public function getStorage($entity_type_id) {
    if ($entity_type_id !== 'node') {
      throw new LogicException('Only node storage should be consulted.');
    }
    return $this->testStorage;
  }
};
$compute = new class implements EdooVillageComputeInterface {
  public int $calls = 0;

  public function setEdooVillageTitle(EntityInterface $entity): void {
    $this->calls++;
    $entity->set('title', 'Computed title');
  }

  public function enqueueRecompute(EntityInterface $entity): void {
    throw new LogicException('Worker must not enqueue another recompute.');
  }
};
$geocoder = new class implements GeocoderInterface {
  public int $calls = 0;
  public ?AddressCollection $result = NULL;
  public ?Exception $failure = NULL;

  public function geocode(GeocodeQuery|string $address, array $providers) {
    throw new LogicException('Worker must only reverse geocode.');
  }

  public function reverse($latitude, $longitude, array $providers): ?AddressCollection {
    $this->calls++;
    if ($providers !== ['googlemaps']) {
      throw new LogicException('Unexpected primary geocoder.');
    }
    if ($this->failure) {
      throw $this->failure;
    }
    return $this->result;
  }
};
$state = new class extends State {
  public array $testValues = [];

  public function __construct() {}

  public function get($key, $default = NULL) {
    return $this->testValues[$key] ?? $default;
  }
};
$worker = new EdooVillageGeocoding([], 'labdoo_edoovillage_geocoding', [], $manager, $compute, $geocoder);
$container = \Drupal::getContainer();
$originalState = $container->get('state');
$originalHttp = $container->get('http_client');
$originalSettings = Settings::getAll();
$enabledSettings = array_replace($originalSettings, ['labdoo_geocoding_disabled' => FALSE, 'labdoo_migrate_is_running' => FALSE]);
$passed = [];
$expectSuspension = static function (callable $operation, string $case) use ($assert): void {
  $suspended = FALSE;
  try {
    $operation();
  }
  catch (SuspendQueueException) {
    $suspended = TRUE;
  }
  $assert($suspended, $case . ' must retain the queue item by suspending.');
};
$address = static fn(string $country, ?string $city): AddressCollection => new AddressCollection([
  new Address('test', new AdminLevelCollection([]), locality: $city, country: new Country($country === 'ES' ? 'Spain' : 'France', $country)),
]);

try {
  // Swaps are process-local: no shared state flags are written to the database.
  $container->set('state', $state);
  new Settings($enabledSettings);
  $container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler([]))]));

  foreach ([NULL, 'bad', 8, [], ['nid' => 0], ['nid' => -1], ['nid' => 'bad'], ['nid' => 1.5]] as $data) {
    $worker->processItem($data);
  }
  $assert($storage->loads === 0 && $geocoder->calls === 0, 'Malformed work must not load an entity or call a provider.');
  $passed[] = 'malformed work';

  $worker->processItem(['nid' => 999]);
  $assert($geocoder->calls === 0, 'Deleted nodes must not call a provider.');
  $passed[] = 'deleted node';

  $storage->nodes[1] = $nodeFactory([], 'hub');
  $worker->processItem(['nid' => 1]);
  $assert($storage->nodes[1]->saves === 0 && $geocoder->calls === 0, 'Other bundles must not be changed.');
  $passed[] = 'wrong bundle';

  $node = $storage->nodes[1] = $nodeFactory(['field_country' => 'ES', 'field_city' => 'Madrid']);
  $worker->processItem(['nid' => 1]);
  $assert($node->saves === 0 && $geocoder->calls === 0 && $compute->calls === 0, 'Resolved work must not trigger APIs or saves.');
  $passed[] = 'already resolved';

  foreach (['labdoo_geocoding_disabled', 'labdoo_migrate_is_running'] as $key) {
    $node = $storage->nodes[1] = $nodeFactory();
    $state->testValues = [$key => TRUE];
    $expectSuspension(static fn() => $worker->processItem(['nid' => 1]), 'Disabled state ' . $key);
    $assert($node->saves === 0 && $geocoder->calls === 0, 'Disabled state must not call APIs or save.');
    $passed[] = 'state guard ' . $key;
    $state->testValues = [];
    new Settings(array_replace($enabledSettings, [$key => TRUE]));
    $expectSuspension(static fn() => $worker->processItem(['nid' => 1]), 'Disabled setting ' . $key);
    $assert($node->saves === 0 && $geocoder->calls === 0, 'Disabled settings must not call APIs or save.');
    $passed[] = 'settings guard ' . $key;
    new Settings($enabledSettings);
  }

  foreach ([NULL, new AddressCollection([])] as $result) {
    $node = $storage->nodes[1] = $nodeFactory();
    $geocoder->result = $result;
    $before = $geocoder->calls;
    $expectSuspension(static fn() => $worker->processItem(['nid' => 1]), 'Empty result');
    $assert($node->saves === 0 && $geocoder->calls === $before + 1, 'Empty provider result must not cause no-op save.');
    $passed[] = $result === NULL ? 'null result retained' : 'empty collection retained';
  }

  foreach ([NULL, [], ['lat' => 0, 'lon' => 0], ['lat' => -90, 'lon' => -180], ['lat' => 91, 'lon' => 4], ['lat' => 40, 'lon' => 181], ['lat' => NAN, 'lon' => 4]] as $location) {
    $node = $storage->nodes[1] = $nodeFactory(['field_location' => $location]);
    $before = $geocoder->calls;
    $worker->processItem(['nid' => 1]);
    $assert($node->saves === 0 && $geocoder->calls === $before, 'Invalid coordinates must not invoke paid geocoding or save.');
  }
  $passed[] = 'missing and invalid coordinates';

  $node = $storage->nodes[1] = $nodeFactory(['field_country' => 'ES']);
  $geocoder->result = $address('ES', NULL);
  $before = $geocoder->calls;
  $beforeCompute = $compute->calls;
  $expectSuspension(static fn() => $worker->processItem(['nid' => 1]), 'Unhelpful partial result');
  $assert($geocoder->calls === $before + 1 && $node->saves === 0 && $compute->calls === $beforeCompute, 'A result that does not resolve the missing city must retain work without saving.');
  $assert($node->testFields['field_country'] === 'ES' && $node->testFields['field_city'] === '', 'An unhelpful partial result must preserve existing fields.');
  $passed[] = 'unhelpful partial result retained';

  $node = $storage->nodes[1] = $nodeFactory(['field_country' => 'ES']);
  $geocoder->result = $address('ES', 'Madrid');
  $beforeCompute = $compute->calls;
  $worker->processItem(['nid' => 1]);
  $assert($node->testFields['field_country'] === 'ES' && $node->testFields['field_city'] === 'Madrid', 'Resolved city must be persisted while keeping the country.');
  $assert($node->saves === 1 && $compute->calls === $beforeCompute + 1 && $node->testFields['title'] === 'Computed title', 'One material change must cause exactly one title recompute and save.');
  $assert($node->skip_geocoding_enqueue, 'Successful update must suppress re-enqueue.');
  $passed[] = 'city updated once';
  $before = $geocoder->calls;
  $worker->processItem(['nid' => 1]);
  $assert($node->saves === 1 && $geocoder->calls === $before, 'Duplicate deliveries must be idempotent.');
  $passed[] = 'duplicate delivery';

  $node = $storage->nodes[1] = $nodeFactory(['field_city' => 'Existing city']);
  $geocoder->result = $address('FR', 'Another city');
  $worker->processItem(['nid' => 1]);
  $assert($node->testFields['field_country'] === 'FR' && $node->testFields['field_city'] === 'Existing city' && $node->saves === 1, 'Country repair must preserve an existing city.');
  $passed[] = 'existing city preserved';

  $node = $storage->nodes[1] = $nodeFactory(['field_country' => 'ES']);
  $geocoder->failure = new RuntimeException('API keys with referer restrictions cannot be used.');
  $history = [];
  $stack = HandlerStack::create(new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], json_encode(['address' => ['country_code' => 'es', 'city' => 'Madrid']])),
  ]));
  $stack->push(Middleware::history($history));
  $container->set('http_client', new Client(['handler' => $stack]));
  $worker->processItem(['nid' => 1]);
  $assert(count($history) === 1 && $history[0]['options']['timeout'] === 5 && $history[0]['options']['connect_timeout'] === 3, 'Fallback must make one bounded HTTP request.');
  $assert($node->saves === 1 && $node->testFields['field_city'] === 'Madrid', 'Fallback result must repair the city once.');
  $passed[] = 'bounded fallback';

  $node = $storage->nodes[1] = $nodeFactory();
  $container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(429, [], 'Rate limited'),
  ]))]));
  $expectSuspension(static fn() => $worker->processItem(['nid' => 1]), 'Blocked primary and fallback');
  $assert($node->saves === 0, 'Both blocked providers must retain work without saving.');
  $passed[] = 'circuit breaker retains work';
}
finally {
  $container->set('state', $originalState);
  $container->set('http_client', $originalHttp);
  new Settings($originalSettings);
}

echo json_encode([
  'passed' => count($passed),
  'cases' => $passed,
  'entity_writes' => 0,
  'external_http_requests' => 0,
  'worker_sha256' => hash_file('sha256', $workerSource),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
