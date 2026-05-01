<?php

namespace Drupal\labdoo_common\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * StatisticsCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
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
        'team_comment',
        'hub',
        'edoovillage',
        'dootrip',
        'action',
        'labdoo_story',
        'basic_page',
      ],
      'user' => [
        'user',
      ],
      'mini_wiki_page' => [
        'mini_wiki_page',
      ],
    ];

    $rows = [];
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

          $rows[] = [
            'Type' => $entity_type_id,
            'Bundle' => $bundle,
            'Total' => $count,
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

    $this->io()->table(['Entity Type', 'Bundle', 'Total'], $rows);
  }

}
