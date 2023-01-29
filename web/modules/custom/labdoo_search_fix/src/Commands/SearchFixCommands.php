<?php

namespace Drupal\labdoo_search_fix\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for fixing Search API configuration.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SearchFixCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new SearchFixCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Fixes the Search API index configuration.
   *
   * @command labdoo-search:fix-index
   * @aliases lsfi
   * @usage labdoo-search:fix-index
   *   Fixes the Search API index configuration.
   */
  public function fixIndex(): void {
    $this->logger->notice('Fixing Search API index configuration...');

    try {
      // Load all node types.
      $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
      $node_type_ids = array_keys($node_types);

      // Load the Search API index.
      $index_storage = $this->entityTypeManager->getStorage('search_api_index');
      $index = $index_storage->load('default_index');

      if (!$index) {
        $this->logger->error('Could not load the default Search API index.');
        return;
      }

      // Get the current fields.
      $fields = $index->getFields();
      
      // Check if the rendered_item field exists.
      if (!isset($fields['rendered_item'])) {
        $this->logger->error('The rendered_item field does not exist in the index.');
        return;
      }

      // Get the current configuration.
      $configuration = $fields['rendered_item']->getConfiguration();
      
      // Update the view mode configuration to include all node types.
      $view_modes = [];
      foreach ($node_type_ids as $node_type_id) {
        $view_modes[$node_type_id] = 'search_index';
      }
      
      $configuration['view_mode']['entity:node'] = $view_modes;
      
      // Update the field configuration.
      $fields['rendered_item']->setConfiguration($configuration);
      
      // Save the index.
      $index->save();
      
      $this->logger->success('Successfully updated the Search API index configuration.');
      $this->logger->notice('Please rebuild the index using: drush search-api:rebuild-tracker && drush search-api:index');
    }
    catch (\Exception $e) {
      $this->logger->error('An error occurred: ' . $e->getMessage());
    }
  }

}
