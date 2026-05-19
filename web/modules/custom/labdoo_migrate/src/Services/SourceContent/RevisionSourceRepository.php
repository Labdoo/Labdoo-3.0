<?php

namespace Drupal\labdoo_migrate\Services\SourceContent;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Repository for node revisions from the source database.
 */
class RevisionSourceRepository implements RevisionSourceRepositoryInterface {

  use LoggerAwareTrait;

  /**
   * The external connection manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface
   */
  protected ConnectionManagerInterface $externalConnectionManager;

  /**
   * RevisionSourceRepository constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface $externalConnectionManager
   *   The external connection manager.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerChannelFactory,
    ConnectionManagerInterface $externalConnectionManager
  ) {
    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->externalConnectionManager = $externalConnectionManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getRevisionsByNid(int $nid): array {
    $revisions = $this->externalConnectionManager
      ->setConnection()
      ->select('node_revision', 'nr')
      ->fields('nr', ['vid', 'nid', 'uid', 'title', 'log', 'timestamp', 'status'])
      ->condition('nid', $nid)
      ->orderBy('vid', 'ASC')
      ->execute()
      ->fetchAll();

    $this->externalConnectionManager->restoreConnection();

    return $revisions;
  }

}
