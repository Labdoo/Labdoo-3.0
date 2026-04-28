<?php

namespace Drupal\labdoo_edoovillage\Service\Repository;

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
   * EdooVillageRepository constructor.
   *
   * @param \Drupal\labdoo_edoovillage\Service\SequenceManagerInterface $sequenceManager
   *   The sequence manager.
   */
  public function __construct(SequenceManagerInterface $sequenceManager) {
    $this->sequenceManager = $sequenceManager;
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

}
