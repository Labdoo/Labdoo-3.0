<?php

namespace Drupal\labdoo_migrate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the migration dashboard.
 */
class MigrationDashboardController extends ControllerBase {

  /**
   * The migration tracker.
   *
   * @var \Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface
   */
  private MigrationTrackerInterface $migrationTracker;

  /**
   * MigrationDashboardController constructor.
   *
   * @param \Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface $migrationTracker
   *   The migration tracker.
   */
  public function __construct(MigrationTrackerInterface $migrationTracker) {
    $this->migrationTracker = $migrationTracker;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container): self {
    $migrationTracker = $container->get('labdoo_migrate.tracking.migration_tracker');
    if (!$migrationTracker instanceof MigrationTrackerInterface) {
      throw new \RuntimeException('Invalid migration tracker service.');
    }

    return new self($migrationTracker);
  }

  /**
   * Builds the migration dashboard page.
   */
  public function build(): array {
    $rows = [];
    foreach ($this->migrationTracker->getDashboardRows() as $item) {
      $rows[] = [
        'data' => [
          ['data' => $item['entity_type']],
          ['data' => $item['d7_count']],
          ['data' => $item['d10_count']],
          ['data' => $item['migrated_count']],
          ['data' => $item['last_migration']],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Entity type'),
        $this->t('Drupal 7 entities'),
        $this->t('Drupal 10 entities'),
        $this->t('Migrated from Drupal 7'),
        $this->t('Latest migration'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No migration types found.'),
    ];
  }

}
