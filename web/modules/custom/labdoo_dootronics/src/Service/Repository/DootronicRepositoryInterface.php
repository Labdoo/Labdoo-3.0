<?php

namespace Drupal\labdoo_dootronics\Service\Repository;

use Drupal\node\NodeInterface;

/**
 * Interface for dootronic repositories.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface DootronicRepositoryInterface {

  /**
   * Generates a dootronic ID and stores it to prevent collisions.
   *
   * @return string
   *   The dootronic ID.
   *
   * @throws \Drupal\labdoo_dootronics\Exception\LockException
   */
  public function generateId(): string;

  /**
   * Updates the dootronic ID. Useful for bulk migration.
   *
   * @param int $sequenceNumber
   *   The sequence number to commit.
   *
   * @return string
   *   The new title
   */
  public function updateId(int $sequenceNumber): string;

  /**
   * Loads a dootronic by ID.
   *
   * @param int $dootronicId
   *   The dootronic ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The entity or NULL in case of error.
   */
  public function load(int $dootronicId): ?NodeInterface;

  /**
   * Loads a dootronic by ID.
   *
   * @param string $dootronicLabel
   *   The dootronic label.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The dootronic or NULL in case of error.
   */
  public function loadByLabel(string $dootronicLabel): ?NodeInterface;

  /**
   * Loads dootronics by properties.
   *
   * @param array $properties
   *   The properties array.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The loaded dootronics.
   */
  public function loadByProperties(array $properties): array;

  /**
   * Loads dootronics by IDs.
   *
   * @param array $dootronicIds
   *   The dootronic IDs array.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The loaded dootronics.
   */
  public function loadByIds(array $dootronicIds): array;

  /**
   * Saves a dootronic.
   *
   * @param \Drupal\node\NodeInterface $dootronic
   *   The dootronic to be saved.
   *
   * @return bool
   *   The result of the operation.
   */
  public function saveEntity(NodeInterface $dootronic): bool;

  /**
   * Generates the QR code.
   *
   * @param NodeInterface $dootronic
   *   The dootronic.
   * @param int $size
   *   The size of the QR code.
   */
  public function generateQrCode(
    NodeInterface $dootronic,
    int $size = 60,
  ): string;

  /**
   * Returns the watt-hours of a dootronic.
   *
   * @param \Drupal\node\NodeInterface $dootronic
   *   The dootronic.
   *
   * @return string
   *   The computed watt/hours.
   */
  public function computeWattHours(NodeInterface $dootronic): string;

  /**
   * Follows a dootronic with the current user.
   *
   * @param \Drupal\node\NodeInterface $dootronic
   *   The dootronic.
   *
   * @return bool
   *   The result of the operation.
   */
  public function follow(NodeInterface $dootronic): bool;

  /**
   * Unfollows a dootronic with the current user.
   *
   * @param \Drupal\node\NodeInterface $dootronic
   *   The dootronic.
   *
   * @return bool
   *   The result of the operation.
   */
  public function unfollow(NodeInterface $dootronic): bool;

  /**
   * Changes the status of the "pick me up" flag.
   *
   * @param \Drupal\node\NodeInterface $dootronic
   *   The dootronic.
   * @param bool $status
   *   The status of the flag.
   *
   * @return bool
   *   The result of the operation.
   */
  public function togglePickMeUp(NodeInterface $dootronic, bool $status): bool;

  /**
   * Checks if the current user is following the given dootronic.
   *
   * @param \Drupal\node\NodeInterface $dootronic
   *   The dootronic.
   *
   * @return false|int
   *   FALSE if not found, otherwise the delta value.
   */
  public function isCurrentUserFollowingDootronic(NodeInterface $dootronic);

  /**
   * Retrieves the dootronics in the given range of nids.
   *
   * @param int $startingDootronicId
   *   The starting dootronic ID.
   * @param int $endingDootronicId
   *   The ending dootronic ID.
   *
   * @return \Drupal\node\NodeInterface[]
   *   An array with the dootronics loaded, or empty in case of error.
   */
  public function getDootronicsInRange(
    int $startingDootronicId,
    int $endingDootronicId,
  ): array;

  /**
   * Retrieves the previous dootronic ID by title.
   *
   * @param string $title
   *   The dootronic title.
   *
   * @return int
   *   The previous dootronic ID.
   */
  public function getPreviousDootronicByTitle(string $title): int;

  /**
   * Retrieves the next dootronic ID by title.
   *
   * @param string $title
   *   The dootronic title.
   *
   * @return int
   *   The next dootronic ID.
   */
  public function getNextDootronicByTitle(string $title): int;

  /**
   * Retrieves the allowed field values for a list field.
   *
   * @param string $entityType
   *   The entity type, i.e. 'node'.
   * @param string $bundle
   *   The entity bundle, i.e. 'article'.
   * @param string $fieldName
   *   The field name, i.e. 'field_cpu_type'.
   *
   * @return array
   *   An array with the allowed values.
   */
  public function getfieldAllowedValues(
    string $entityType,
    string $bundle,
    string $fieldName,
  ): array;

  /**
   * Clones a dootronic.
   *
   * @param \Drupal\node\NodeInterface $originalDootronic
   *   The original dootronic.
   *
   * @return \Drupal\node\NodeInterface
   *   The cloned dootronic.
   */
  public function clone(NodeInterface $originalDootronic): NodeInterface;

  /**
   * Sets dootronic fields with external data.
   *
   * @param \Drupal\node\NodeInterface $dootronic
   *   The dootronic entity.
   * @param array $data
   *   The external data.
   * @param string $tag
   *   The dootronic's tag.
   * @param bool $edoovillageOnly
   *   If TRUE, only the Edoovillage will be set.
   *
   * @return void
   *   The resulting value.
   */
  public function setDootronicExternalData(
    NodeInterface $dootronic,
    array $data,
    string $tag,
    bool $edoovillageOnly,
  ): void;

  /**
   * Retrieves aggregated stats for dootronics by status.
   *
   * @param int|null $userId
   *   Filter by user ID if provided.
   *
   * @return array
   *   An array with stats keyed by status.
   */
  public function getStats(?int $userId = NULL): array;

  /**
   * Invalidates the cache for a dootronic.
   *
   * @param int $dootronicId
   *   The dootronic ID.
   *
   * @return void
   *   The resulting value.
   */
  public function invalidateCache(int $dootronicId): void;

}
