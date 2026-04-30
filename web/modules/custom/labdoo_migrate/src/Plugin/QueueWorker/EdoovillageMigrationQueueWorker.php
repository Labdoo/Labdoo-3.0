<?php

namespace Drupal\labdoo_migrate\Plugin\QueueWorker;

/**
 * Queue worker that processes edoovillage migration.
 *
 * @QueueWorker(
 *   id = "labdoo_migrate_migration_edoovillage",
 *   title = @Translation("Process edoovillage migration entities"),
 * )
 */
class EdoovillageMigrationQueueWorker extends MigrationQueueWorkerBase {}
