<?php

namespace Drupal\labdoo_migrate\Plugin\QueueWorker;

/**
 * Queue worker that processes user migration.
 *
 * @QueueWorker(
 *   id = "labdoo_migrate_migration_user",
 *   title = @Translation("Process user migration entities"),
 * )
 */
class UserMigrationQueueWorker extends MigrationQueueWorkerBase {}
