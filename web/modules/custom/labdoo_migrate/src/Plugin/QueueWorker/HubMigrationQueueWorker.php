<?php

namespace Drupal\labdoo_migrate\Plugin\QueueWorker;

/**
 * Queue worker that processes hub migration.
 *
 * @QueueWorker(
 *   id = "labdoo_migrate_migration_hub",
 *   title = @Translation("Process hub migration entities"),
 * )
 */
class HubMigrationQueueWorker extends MigrationQueueWorkerBase {}
