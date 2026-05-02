<?php

namespace Drupal\labdoo_common\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager;
use Drush\Commands\DrushCommands;

/**
 * Statistics Drush commands.
 */
class StatisticsCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The external connection manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager
   */
  protected ExternalConnectionManager $externalConnectionManager;

  /**
   * StatisticsCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager $externalConnectionManager
   *   The external connection manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ExternalConnectionManager $externalConnectionManager) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->externalConnectionManager = $externalConnectionManager;
  }

  /**
   * Gets the total number of entities by type and bundle.
   *
   * @command labdoo:entity-stats
   * @aliases l-stats
   * @usage drush labdoo:entity-stats
   *   Shows the total number of entities by type and bundle.
   */
  public function entityStats(): void {
    $entity_types = [
      'node' => [
        'dootronic',
        'gallery',
        'team',
        'team_post',
        'task_team',
        'hub',
        'edoovillage',
        'dootrip',
        'action',
        'labdoo_story',
        'page',
      ],
      'user' => [
        'user',
      ],
      'comment' => [
        'comment',
      ],
      'mini_wiki_page' => [
        'mini_wiki_page',
      ],
    ];

    $d7_bundle_mapping = [
      'dootronic' => 'laptop',
      'gallery' => 'node_gallery_gallery',
      'team_post' => 'team_page',
      'task_team' => 'team_task',
      'mini_wiki_page' => 'book',
    ];

    $rows = [];
    $external_connection = $this->externalConnectionManager->setConnection();

    foreach ($entity_types as $entity_type_id => $bundles) {
      try {
        $storage = $this->entityTypeManager->getStorage($entity_type_id);
        $definition = $this->entityTypeManager->getDefinition($entity_type_id);
        $bundle_key = $definition->getKey('bundle');

        foreach ($bundles as $bundle) {
          $query = $storage->getQuery()->accessCheck(FALSE);
          if ($bundle_key && $entity_type_id !== 'user') {
            $query->condition($bundle_key, $bundle);
          }
          $count = $query->count()->execute();

          $count_d7 = 0;
          $bundle_d7 = $d7_bundle_mapping[$bundle] ?? $bundle;

          try {
            if ($entity_type_id === 'node' || $entity_type_id === 'mini_wiki_page') {
              $count_d7 = $external_connection->select('node', 'n')
                ->condition('type', $bundle_d7)
                ->countQuery()
                ->execute()
                ->fetchField();
            }
            elseif ($entity_type_id === 'user') {
              $count_d7 = $external_connection->select('users', 'u')
                ->condition('uid', 0, '>')
                ->countQuery()
                ->execute()
                ->fetchField();
            }
            elseif ($entity_type_id === 'comment') {
              $count_d7 = $external_connection->select('comment', 'c')
                ->countQuery()
                ->execute()
                ->fetchField();
            }
          }
          catch (\Exception $e) {
            // If the table doesn't exist in D7 or any other error occurs.
            $count_d7 = 'N/A';
          }

          $rows[] = [
            'Type' => $entity_type_id,
            'Bundle' => $bundle,
            'D10 Total' => $count,
            'D7 Total' => $count_d7,
          ];
        }
      }
      catch (\Exception $e) {
        $this->logger()->error(dt('Error counting entities for @type: @message', [
          '@type' => $entity_type_id,
          '@message' => $e->getMessage(),
        ]));
      }
    }

    $this->externalConnectionManager->restoreConnection();

    $this->io()->table(['Entity Type', 'Bundle', 'D10 Total', 'D7 Total'], $rows);
  }

}
