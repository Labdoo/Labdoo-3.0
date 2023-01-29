<?php

namespace Drupal\queue_manager\Model;

/**
 * Interface for queue data DTOs.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface QueueDataModelInterface {

  /**
   * Retrieves the queue ID.
   *
   * @return null|string
   *   The queue ID.
   */
  public function getQueueId(): ?string;

  /**
   * Sets the queue ID.
   *
   * @param string $queueId
   *   The queue ID.
   */
  public function setQueueId(string $queueId): void;

  /**
   * Retrieves the queue data.
   *
   * @return null|mixed
   *   The queue data.
   */
  public function getData();

  /**
   * Sets the queue data.
   *
   * @param mixed $data
   *   The queue data.
   */
  public function setData($data): void;

  /**
   * Retrieves the timestamp.
   *
   * @return \DateTime|null
   *   The timestamp.
   */
  public function getTimestamp(): ?\DateTime;

  /**
   * Sets the timestamp.
   *
   * @param \DateTime $timestamp
   *   The timestamp.
   */
  public function setTimestamp(\DateTime $timestamp): void;

}
