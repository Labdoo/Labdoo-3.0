<?php

namespace Drupal\labdoo_migrate\Plugin\QueueWorker;

/**
 * Queue worker that processes dootrip migration.
 *
 * @QueueWorker(
 *   id = "labdoo_migrate_migration_dootrip",
 *   title = @Translation("Process dootrip migration entities"),
 * )
 */
class DootripMigrationQueueWorker extends MigrationQueueWorkerBase {}
