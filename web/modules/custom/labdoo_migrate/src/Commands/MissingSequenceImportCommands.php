<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Database\Connection;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Commands to detect sequence gaps and import missing nodes from Drupal 7.
 */
class MissingSequenceImportCommands extends DrushCommands {

  /**
   * Mapping between destination bundle and Drupal 7 source bundle.
   */
  private const BUNDLE_MAP = [
    'dootronic' => 'laptop',
    'dootrip' => 'dootrip',
    'edoovillage' => 'edoovillage',
  ];

  /**
   * Regex patterns used to extract the sequence number by bundle.
   */
  private const BUNDLE_SEQUENCE_PATTERNS = [
    'dootronic' => '/^([0-9]{9})$/',
    'dootrip' => '/#\s*([0-9]+)/',
    'edoovillage' => '/#\s*([0-9]+)/',
  ];

  /**
   * Drupal 10 database connection.
   */
  public function __construct(
    protected Connection $database,
    protected ConnectionManagerInterface $externalConnectionManager,
  ) {
    parent::__construct();
  }

  /**
   * Finds numeric title gaps and imports the missing nodes from Drupal 7.
   *
   * @command labdoo:import-missing-sequences
   * @aliases lims
   * @option bundles Comma-separated list of destination bundles (dootronic,dootrip,edoovillage).
   * @option limit Maximum number of missing titles to import per bundle. Use -1 for no limit.
   * @option dry-run Detect and print what would be imported without importing.
   *
   * @usage drush labdoo:import-missing-sequences
   *   Detects and imports all missing numeric titles for dootronic, dootrip and edoovillage.
   * @usage drush labdoo:import-missing-sequences --bundles=dootronic --dry-run
   *   Shows missing dootronic titles and matching Drupal 7 nodes without importing.
   */
  public function importMissingSequences(array $options = [
    'bundles' => 'dootronic,dootrip,edoovillage',
    'limit' => -1,
    'dry-run' => FALSE,
  ]): void {
    $requestedBundles = array_filter(array_map('trim', explode(',', (string) $options['bundles'])));
    $invalidBundles = array_diff($requestedBundles, array_keys(self::BUNDLE_MAP));

    if (!empty($invalidBundles)) {
      $this->io()->error('Invalid bundle values: ' . implode(', ', $invalidBundles));
      return;
    }

    if (empty($requestedBundles)) {
      $this->io()->error('No bundles provided.');
      return;
    }

    $limit = (int) $options['limit'];
    $dryRun = !empty($options['dry-run']);

    try {
      $externalConnection = $this->externalConnectionManager->setConnection();
    }
    catch (\Exception $e) {
      $this->io()->error('Error connecting to Drupal 7 database: ' . $e->getMessage());
      return;
    }

    $bundlesProgress = $this->io()->createProgressBar(count($requestedBundles));
    $bundlesProgress->setFormat('Processing bundles: %current%/%max% [%bar%] %percent:3s%%');
    $bundlesProgress->start();

    foreach ($requestedBundles as $bundle) {
      $sourceBundle = self::BUNDLE_MAP[$bundle];
      $this->io()->section(sprintf('Bundle: %s (source: %s)', $bundle, $sourceBundle));

      $destinationRows = $this->getDestinationTitles($bundle);
      $sequenceMap = $this->extractSequenceMap($bundle, $destinationRows);

      if (count($sequenceMap) < 2) {
        $this->io()->note('Not enough sequence values to calculate gaps.');
        $bundlesProgress->advance();
        continue;
      }

      $missingNumbers = $this->buildMissingNumbers(array_keys($sequenceMap));
      if ($limit > -1) {
        $missingNumbers = array_slice($missingNumbers, 0, $limit);
      }

      if (empty($missingNumbers)) {
        $this->io()->success('No sequence gaps found.');
        $bundlesProgress->advance();
        continue;
      }

      $this->io()->text(sprintf('Missing sequence numbers found: %d', count($missingNumbers)));
      $this->io()->note(sprintf('Missing sequence preview: %s', $this->buildMissingPreview($bundle, $missingNumbers)));

      $sourceRows = $this->getSourceRows($externalConnection, $sourceBundle);
      $sourceSequenceMap = $this->extractSequenceMap($bundle, $sourceRows);
      $sourceMatches = $this->filterSourceByMissingNumbers($sourceSequenceMap, $missingNumbers);
      if (empty($sourceMatches)) {
        $this->io()->warning('No matching nodes found in Drupal 7 for these sequence numbers.');
        $bundlesProgress->advance();
        continue;
      }

      $sourceNids = array_column($sourceMatches, 'nid');
      $foundNumbers = array_map('intval', array_keys($sourceMatches));
      $notFoundInD7 = array_values(array_diff($missingNumbers, $foundNumbers));

      $this->io()->text(sprintf('Matching nodes in Drupal 7: %d', count($sourceNids)));
      if (!empty($notFoundInD7)) {
        $this->io()->note(sprintf('Missing numbers not found in Drupal 7: %d (%s)', count($notFoundInD7), $this->buildMissingPreview($bundle, $notFoundInD7)));
      }

      if ($dryRun) {
        $preview = implode(', ', array_slice($sourceNids, 0, 30));
        $this->io()->comment('Dry-run enabled. Would import source nids: ' . $preview . (count($sourceNids) > 30 ? ', ...' : ''));
        $bundlesProgress->advance();
        continue;
      }

      $this->runSynchronization($sourceBundle, $sourceNids);
      $bundlesProgress->advance();
    }

    $bundlesProgress->finish();
    $this->io()->newLine(2);

    $this->externalConnectionManager->restoreConnection();
  }

  /**
   * Returns destination rows with nid/title for a bundle.
   */
  protected function getDestinationTitles(string $bundle): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid', 'title']);
    $query->condition('n.type', $bundle);
    $query->condition('n.status', 1);

    return $query->execute()->fetchAllAssoc('nid', \PDO::FETCH_ASSOC);
  }

  /**
   * Builds all missing sequence numbers between consecutive sequence values.
   */
  protected function buildMissingNumbers(array $numbers): array {
    sort($numbers, SORT_NUMERIC);
    $missingNumbers = [];
    $numbers = array_map('intval', $numbers);
    $total = count($numbers);

    for ($i = 1; $i < $total; $i++) {
      $previous = $numbers[$i - 1];
      $current = $numbers[$i];
      if ($current - $previous < 2) {
        continue;
      }

      for ($missing = $previous + 1; $missing < $current; $missing++) {
        $missingNumbers[] = $missing;
      }
    }

    return $missingNumbers;
  }

  /**
   * Returns Drupal 7 rows for a source bundle.
   */
  protected function getSourceRows(Connection $externalConnection, string $sourceBundle): array {
    return $externalConnection->select('node', 'n')
      ->fields('n', ['nid', 'title'])
      ->condition('n.type', $sourceBundle)
      ->condition('n.status', 1)
      ->execute()
      ->fetchAllAssoc('nid', \PDO::FETCH_ASSOC);
  }

  /**
   * Extracts sequence number => row map for the given bundle.
   */
  protected function extractSequenceMap(string $bundle, array $rows): array {
    $pattern = self::BUNDLE_SEQUENCE_PATTERNS[$bundle] ?? NULL;
    if ($pattern === NULL) {
      return [];
    }

    $map = [];
    foreach ($rows as $row) {
      $title = (string) ($row['title'] ?? '');
      if (preg_match($pattern, $title, $matches) !== 1) {
        continue;
      }

      $sequence = (int) ltrim($matches[1], '0');
      if ($matches[1] === '0' || $sequence > 0) {
        $map[$sequence] = $row;
      }
    }

    ksort($map, SORT_NUMERIC);
    return $map;
  }

  /**
   * Filters source sequence map by missing numbers.
   */
  protected function filterSourceByMissingNumbers(array $sourceSequenceMap, array $missingNumbers): array {
    $result = [];
    $progressBar = $this->createProgressBar(count($missingNumbers), 'Matching missing numbers in Drupal 7');
    $progressBar->start();

    foreach ($missingNumbers as $number) {
      if (!isset($sourceSequenceMap[$number])) {
        $progressBar->advance();
        continue;
      }
      $result[$number] = $sourceSequenceMap[$number];
      $progressBar->advance();
    }

    $progressBar->finish();
    $this->io()->newLine();

    return $result;
  }

  /**
   * Creates a progress bar with a common format.
   */
  protected function createProgressBar(int $max, string $label): ProgressBar {
    $progressBar = $this->io()->createProgressBar(max($max, 1));
    $progressBar->setFormat(sprintf('%s: %%current%%/%%max%% [%%bar%%] %%percent:3s%%%%', $label));

    return $progressBar;
  }

  /**
   * Builds a human-readable preview of missing sequence numbers.
   */
  protected function buildMissingPreview(string $bundle, array $numbers, int $limit = 10): string {
    if (empty($numbers)) {
      return 'none';
    }

    $formatted = array_map(fn(int $number): string => $this->formatSequenceNumber($bundle, $number), $numbers);
    $total = count($formatted);

    if ($total <= ($limit * 2)) {
      return implode(', ', $formatted);
    }

    $first = array_slice($formatted, 0, $limit);
    $last = array_slice($formatted, -$limit);
    return sprintf('first: %s | last: %s', implode(', ', $first), implode(', ', $last));
  }

  /**
   * Formats a sequence number according to bundle conventions.
   */
  protected function formatSequenceNumber(string $bundle, int $number): string {
    if ($bundle === 'dootronic') {
      return str_pad((string) $number, 9, '0', STR_PAD_LEFT);
    }

    return (string) $number;
  }

  /**
   * Calls the existing synchronization command for the selected source IDs.
   */
  protected function runSynchronization(string $sourceBundle, array $sourceNids): void {
    $this->io()->text(sprintf('Importing %d nodes for source type "%s"...', count($sourceNids), $sourceBundle));

    $result = Drush::drush(
      Drush::aliasManager()->getSelf(),
      'labdoo-synchronize-content',
      [$sourceBundle],
      [
        'nids' => implode(',', $sourceNids),
        'mode' => 'create',
      ]
    )->run();

    if ($result === 0) {
      $this->io()->success('Import finished successfully.');
      return;
    }

    $this->io()->error('Import failed for source type: ' . $sourceBundle);
  }

}
