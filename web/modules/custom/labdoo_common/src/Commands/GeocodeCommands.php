<?php

namespace Drupal\labdoo_common\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * A Drush commandfile.
 */
class GeocodeCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * GeocodeCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * Recodifica entidades de un bundle buscando campos de tipo geofield.
   *
   * @param string $bundle
   *   El bundle de la entidad (ej. edoovillage, hub, etc.).
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   *
   * @option entity_type El tipo de entidad (por defecto 'node').
   * @option limit Limita el número de entidades a procesar.
   * @usage drush labdoo:regeocode edoovillage
   *   Recodifica todos los nodos de tipo edoovillage.
   * @usage drush labdoo:regeocode hub --entity_type=node
   *   Recodifica todos los nodos de tipo hub.
   *
   * @command labdoo:regeocode
   * @aliases lregeo
   */
  public function regeocode(
    string $bundle,
    array $options = ['entity_type' => 'node', 'limit' => NULL]
  ): void {
    $entity_type = $options['entity_type'];
    
    // Buscar campos de tipo geofield para este bundle.
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    $geofield_name = NULL;
    
    foreach ($fields as $field_name => $field_definition) {
      if ($field_definition->getType() === 'geofield') {
        $geofield_name = $field_name;
        break;
      }
    }
    
    if (!$geofield_name) {
      $this->logger()->error(dt('No se encontró ningún campo de tipo geofield para el bundle "@bundle" en el tipo de entidad "@entity_type".', [
        '@bundle' => $bundle,
        '@entity_type' => $entity_type,
      ]));
      return;
    }
    
    $this->logger()->notice(dt('Usando el campo "@field" para la recodificación.', ['@field' => $geofield_name]));
    
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $key = $this->entityTypeManager
      ->getDefinition($entity_type)
      ->getKey('bundle');
    $query = $storage->getQuery()
      ->condition($key, $bundle)
      ->accessCheck(FALSE);
    
    if ($options['limit']) {
      $query->range(0, $options['limit']);
    }
    
    $ids = $query->execute();
    
    if (empty($ids)) {
      $this->logger()->warning(dt('No se encontraron entidades para recodificar.'));
      return;
    }
    
    $total = count($ids);
    $this->output()->writeln(dt('Recodificando @count entidades...', ['@count' => $total]));
    
    $chunks = array_chunk($ids, 50);
    $count = 0;
    
    foreach ($chunks as $chunk) {
      $entities = $storage->loadMultiple($chunk);
      foreach ($entities as $entity) {
        if ($entity instanceof FieldableEntityInterface) {
          try {
            // Aseguramos que la entidad no sea tratada como nueva si tiene ID.
            if (!$entity->isNew()) {
              $entity->enforceIsNew(FALSE);
            }
            $entity->save();
          }
          catch (\Exception $e) {
            $this->logger()->error(dt('Error al guardar la entidad @id: @message', [
              '@id' => $entity->id(),
              '@message' => $e->getMessage(),
            ]));
          }
          $count++;
        }
      }
      
      // Limpiar el cache estático del storage para liberar memoria.
      $storage->resetCache($chunk);
      
      $this->output()->writeln(dt('Procesadas @count de @total...', ['@count' => $count, '@total' => $total]));
    }
    
    $this->logger()->success(dt('Se han procesado @count entidades.', ['@count' => $count]));
  }

}
