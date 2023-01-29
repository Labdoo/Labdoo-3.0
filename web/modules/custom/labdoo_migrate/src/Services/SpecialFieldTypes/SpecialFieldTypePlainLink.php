<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager;
use Drupal\Core\Entity\EntityInterface;

/**
 * The special field type for links outside a paragraph.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypePlainLink implements SpecialFieldTypeInterface {

  /**
   * The external connection manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager
   */
  private ExternalConnectionManager $externalConnectionManager;

  /**
   * SpecialFieldTypeLink constructor.
   *
   * @param \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager $externalConnectionManager
   *   The external connection manager.
   */
  public function __construct(
    ExternalConnectionManager $externalConnectionManager
  ) {

    $this->externalConnectionManager = $externalConnectionManager;
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(
    $value,
    array $metadata,
    EntityInterface $entity,
    string $mainLangCode
  ) {

    $dataSource = $metadata['source_data'];
    $dataTable = $dataSource['table_name'];

    $query = $this->externalConnectionManager
      ->setConnection()
      ->select($dataTable);
    foreach ($dataSource['value_fields'] as $alias => $fieldName) {
      $query->addField($dataTable, $fieldName, $alias);
    }
    $query->condition(
      $dataTable . '.' . $dataSource['bundle_field'],
      $dataSource['bundle_value']
    );
    $query->condition(
      $dataTable . '.' . $dataSource['value_fields']['uri'],
      $value
    );
    $result = $query->execute()->fetchAssoc();

    // Prepends a slash in case the path is not external.
    if (!empty($result['uri'])) {
      if (
        strpos($result['uri'], '://') === FALSE
        && substr($result['uri'], 0, 1) !== '/'
      ) {
        $result['uri'] = '/' . $result['uri'];
      }
    }

    $this->externalConnectionManager->restoreConnection();

    return $result;
  }

  public function filterValue($value) {

    return $value;
  }

}
