<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Commands to migrate only dootronic image references.
 */
class DootronicImageReferenceMigratorCommands extends DrushCommands {

  /**
   * Constructor.
   */
  public function __construct(
    protected ConnectionManagerInterface $externalConnectionManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
  ) {
    parent::__construct();
  }

  /**
   * Migrates only image database references for dootronics.
   *
   * Files must already exist in destination filesystem.
   *
   * @command labdoo:migrate-dootronic-image-references
   * @aliases lmdir
   * @option nids Comma-separated list of Drupal 7 dootronic nids.
   * @option limit Maximum number of source rows to process. Use -1 for no limit.
   * @option dry-run Show what would be updated without saving changes.
   *
   * @usage drush labdoo:migrate-dootronic-image-references --nids=253422
   *   Migrates image reference for a specific dootronic.
   * @usage drush labdoo:migrate-dootronic-image-references --dry-run
   *   Shows pending reference changes without writing to database.
   */
  public function migrateImageReferences(array $options = [
    'nids' => NULL,
    'limit' => -1,
    'dry-run' => FALSE,
  ]): void {
    $nids = $this->parseNids($options['nids'] ?? NULL);
    $limit = (int) ($options['limit'] ?? -1);
    $dryRun = !empty($options['dry-run']);

    try {
      $externalConnection = $this->externalConnectionManager->setConnection();

      $query = $externalConnection->select('node', 'n')
        ->fields('n', ['nid'])
        ->condition('n.type', 'laptop')
        ->condition('n.status', 1)
        ->orderBy('n.nid', 'ASC');

      $query->leftJoin('field_data_field_picture', 'fdp', 'fdp.entity_id = n.nid');
      $query->leftJoin('file_managed', 'fm', 'fm.fid = fdp.field_picture_fid');
      $query->addField('fm', 'uri', 'picture_uri');

      if (!empty($nids)) {
        $query->condition('n.nid', $nids, 'IN');
      }

      if ($limit > -1) {
        $query->range(0, $limit);
      }

      $sourceRows = $query->execute()->fetchAllAssoc('nid');
    }
    catch (\Exception $e) {
      $this->io()->error('Error loading source dootronics: ' . $e->getMessage());
      return;
    }
    finally {
      $this->externalConnectionManager->restoreConnection();
    }

    if (empty($sourceRows)) {
      $this->io()->warning('No dootronics found for the provided filters.');
      return;
    }

    $destinationStorage = $this->entityTypeManager->getStorage('node');
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $canMatchByD7Nid = $destinationStorage
      ->create(['type' => 'dootronic'])
      ->hasField('field_d7_nid');

    $updated = 0;
    $skipped = 0;
    $missingDestination = 0;
    $missingSourceImage = 0;
    $missingFileEntity = 0;
    $matchedByTitle = 0;

    $progress = $this->io()->createProgressBar(count($sourceRows));
    $progress->start();

    foreach ($sourceRows as $sourceNid => $sourceRow) {
      $destinationNodes = [];
      if ($canMatchByD7Nid) {
        $destinationNodes = $destinationStorage->loadByProperties([
          'type' => 'dootronic',
          'field_d7_nid' => (int) $sourceNid,
        ]);
      }
      else {
        $titleCandidates = [
          str_pad((string) $sourceNid, 9, '0', STR_PAD_LEFT),
          (string) $sourceNid,
        ];
        $destinationNodes = $destinationStorage->loadByProperties([
          'type' => 'dootronic',
          'title' => $titleCandidates,
        ]);
        if (!empty($destinationNodes)) {
          $matchedByTitle++;
        }
      }

      if (empty($destinationNodes)) {
        $missingDestination++;
        $skipped++;
        $progress->advance();
        continue;
      }

      $destinationNode = reset($destinationNodes);
      $pictureUri = $sourceRow->picture_uri ?? NULL;

      if (empty($pictureUri)) {
        $missingSourceImage++;
        $skipped++;
        $progress->advance();
        continue;
      }

      $fileEntity = $this->fileRepository->loadByUri($pictureUri);
      if ($fileEntity === NULL) {
        $fileName = basename($pictureUri);
        $filesByName = $fileStorage->loadByProperties(['filename' => $fileName]);
        $fileEntity = !empty($filesByName) ? reset($filesByName) : NULL;
      }

      if ($fileEntity === NULL) {
        $missingFileEntity++;
        $skipped++;
        $this->io()->warning(sprintf(
          'No file entity found for source nid %d and uri %s',
          (int) $sourceNid,
          $pictureUri
        ));
        $progress->advance();
        continue;
      }

      $currentTargetId = (int) ($destinationNode->get('field_picture')->target_id ?? 0);
      $newTargetId = (int) $fileEntity->id();
      if ($currentTargetId === $newTargetId) {
        $skipped++;
        $progress->advance();
        continue;
      }

      if ($dryRun) {
        $this->io()->text(sprintf(
          '[DRY-RUN] D7 nid %d -> D10 nid %d: field_picture %d -> %d',
          (int) $sourceNid,
          (int) $destinationNode->id(),
          $currentTargetId,
          $newTargetId
        ));
        $updated++;
        $progress->advance();
        continue;
      }

      try {
        $destinationNode->set('field_picture', ['target_id' => $newTargetId]);
        $destinationNode->save();
        $updated++;
      }
      catch (\Exception $e) {
        $skipped++;
        $this->io()->error(sprintf(
          'Failed updating dootronic D10 nid %d (source nid %d): %s',
          (int) $destinationNode->id(),
          (int) $sourceNid,
          $e->getMessage()
        ));
      }

      $progress->advance();
    }

    $progress->finish();
    $this->io()->newLine(2);
    $this->io()->success(sprintf(
      'Done. Updated: %d, Skipped: %d, Missing destination: %d, Missing source image: %d, Missing file entity: %d, Title matches: %d',
      $updated,
      $skipped,
      $missingDestination,
      $missingSourceImage,
      $missingFileEntity,
      $matchedByTitle
    ));
  }

  /**
   * Parses a comma-separated list of nids.
   */
  protected function parseNids(?string $nidsOption): array {
    if (empty($nidsOption)) {
      return [];
    }

    $parsed = array_filter(array_map('trim', explode(',', $nidsOption)));
    $parsed = array_map('intval', $parsed);

    return array_values(array_filter($parsed, static fn (int $nid): bool => $nid > 0));
  }

}
