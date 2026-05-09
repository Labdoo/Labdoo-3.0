<?php

namespace Drupal\labdoo_edoovillage\Service\Repository;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\labdoo_edoovillage\Service\SequenceManagerInterface;

/**
 * EdooVillage repository.
 */
class EdooVillageRepository implements EdooVillageRepositoryInterface {

  /**
   * The sequence manager.
   *
   * @var \Drupal\labdoo_edoovillage\Service\SequenceManagerInterface
   */
  protected SequenceManagerInterface $sequenceManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * EdooVillageRepository constructor.
   *
   * @param \Drupal\labdoo_edoovillage\Service\SequenceManagerInterface $sequenceManager
   *   The sequence manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    SequenceManagerInterface $sequenceManager,
    Connection $database
  ) {
    $this->sequenceManager = $sequenceManager;
    $this->database = $database;
  }

  /**
   * {@inheritDoc}
   */
  public function generateId(): int {
    return $this->sequenceManager->get();
  }

  /**
   * {@inheritDoc}
   */
  public function commit(): void {
    $this->sequenceManager->commit();
  }

  /**
   * {@inheritDoc}
   */
  public function getStats(?int $userId = NULL): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->condition('n.type', 'edoovillage');
    $query->condition('n.status', 1);

    if ($userId !== NULL) {
      $query->condition('n.uid', $userId);
    }

    $query->leftJoin('node__field_number_of_laptops_needed', 'f_needed', 'n.nid = f_needed.entity_id AND f_needed.deleted = 0');
    $query->leftJoin('node__field_dootronics_delivered', 'f_delivered', 'n.nid = f_delivered.entity_id AND f_delivered.deleted = 0');
    $query->leftJoin('node__field_dootronics_in_transit', 'f_transit', 'n.nid = f_transit.entity_id AND f_transit.deleted = 0');
    $query->leftJoin('node__field_dootronics_remaining', 'f_remaining', 'n.nid = f_remaining.entity_id AND f_remaining.deleted = 0');

    $query->addExpression('SUM(f_needed.field_number_of_laptops_needed_value)', 'needed');
    $query->addExpression('SUM(f_delivered.field_dootronics_delivered_value)', 'delivered');
    $query->addExpression('SUM(f_transit.field_dootronics_in_transit_value)', 'in_transit');
    $query->addExpression('SUM(f_remaining.field_dootronics_remaining_value)', 'remaining');

    $result = $query->execute()->fetchAssoc();

    return [
      'needed' => (int) ($result['needed'] ?? 0),
      'delivered' => (int) ($result['delivered'] ?? 0),
      'in_transit' => (int) ($result['in_transit'] ?? 0),
      'remaining' => (int) ($result['remaining'] ?? 0),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function load(int $id): ?EntityInterface {
    return \Drupal::entityTypeManager()->getStorage('node')->load($id);
  }

  /**
   * {@inheritDoc}
   */
  public function saveEntity(EntityInterface $entity): void {
    $entity->save();
  }

}
