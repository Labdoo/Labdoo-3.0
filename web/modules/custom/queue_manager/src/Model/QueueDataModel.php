<?php

namespace Drupal\queue_manager\Model;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;

/**
 * Queue Data DTO.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class QueueDataModel implements QueueDataModelInterface, DataModelInterface {

  /**
   * The queue ID, useful for re-queueing.
   *
   * @var null|string
   */
  protected ?string $queueId;

  /**
   * The queue data can be either an array, a primitive, or a serialized object.
   *
   * @var mixed
   */
  protected $data;

  /**
   * The time when the item was created.
   *
   * It is used to cancel this item when a configurable timeout has passed.
   *
   * @var null|\DateTime
   */
  protected ?\DateTime $timestamp;

  /**
   * QueueDataModel constructor.
   */
  public function __construct() {
    $this->data = NULL;
    $this->timestamp = NULL;
    $this->queueId = NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function getQueueId(): ?string {
    return $this->queueId;
  }

  /**
   * {@inheritDoc}
   */
  public function setQueueId(string $queueId): void {
    $this->queueId = $queueId;
  }

  /**
   * {@inheritDoc}
   */
  public function getData() {
    return $this->data;
  }

  /**
   * {@inheritDoc}
   */
  public function setData($data): void {
    $this->data = $data;
  }

  /**
   * {@inheritDoc}
   */
  public function getTimestamp(): ?\DateTime {
    return $this->timestamp;
  }

  /**
   * {@inheritDoc}
   */
  public function setTimestamp(\DateTime $timestamp): void {
    $this->timestamp = $timestamp;
  }

  /**
   * {@inheritDoc}
   */
  public function __serialize(): array {
    return [
      'queue_id' => $this->queueId,
      'timestamp' => $this->timestamp,
      'data' => $this->data,
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function __unserialize(?array $data): void {
    if (is_null($data)) {
      return;
    }

    if (
      !array_key_exists('queue_id', $data)
      || !array_key_exists('data', $data)
      || !array_key_exists('timestamp', $data)
    ) {
      $errorMessage = 'Cannot unserialize the Queue Data: corrupt data provided.';
      throw new InvalidDataTypeException($errorMessage);
    }

    $this->queueId = $data['queue_id'];
    $this->data = $data['data'];
    $this->timestamp = $data['timestamp'];
  }

}
