<?php

namespace Drupal\labdoo_migrate\Plugin\QueueWorker;

/**
 * Queue worker that processes laptop migration.
 *
 * @QueueWorker(
 *   id = "labdoo_migrate_migration_laptop",
 *   title = @Translation("Process laptop migration entities"),
 * )
 */
class LaptopMigrationQueueWorker extends MigrationQueueWorkerBase {}
