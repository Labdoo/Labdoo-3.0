<?php

namespace Drupal\labdoo_migrate\Plugin\QueueWorker;

/**
 * Queue worker that processes action migration.
 *
 * @QueueWorker(
 *   id = "labdoo_migrate_migration_action",
 *   title = @Translation("Process action migration entities"),
 * )
 */
class ActionMigrationQueueWorker extends MigrationQueueWorkerBase {}
