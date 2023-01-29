<?php

namespace Drupal\labdoo_migrate\Model;

/**
 * DTO for field data.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class FieldModel {

  /**
   * The table name.
   *
   * @var string
   */
  private string $tableName;

  /**
   * The key name.
   *
   * @var string
   */
  private string $keyName;

  /**
   * The field name.
   *
   * @var string
   */
  private string $fieldName;

  /**
   * The expression. If set, the field will be ignored.
   *
   * @var string
   */
  private string $expression;

  /**
   * The joins.
   *
   * @var array
   */
  private array $joins;

  /**
   * If set to TRUE, ignores the langcode.
   *
   * @var bool
   */
  private bool $ignoreLangcode;

  /**
   * The field alias.
   *
   * @var string
   */
  private string $fieldAlias;

  /**
   * The operator.
   *
   * @var string
   */
  private string $operator;

  /**
   * The filter prefix.
   *
   * @var string
   */
  private string $filterPrefix;

  /**
   * The filter syffix.
   *
   * @var string
   */
  private string $filterSuffix;

  /**
   * The default value.
   *
   * @var mixed
   */
  private $defaultValue;

  /**
   * The dynamic paragraph model.
   *
   * @var \Drupal\labdoo_migrate\Model\DynamicParagraphModel|null
   */
  private ?DynamicParagraphModel $dynamicParagraph;

  /**
   * The taxonomy model.
   *
   * @var \Drupal\labdoo_migrate\Model\TaxonomyModel|null
   */
  private ?TaxonomyModel $taxonomy;

  /**
   * The join model.
   *
   * @var \Drupal\labdoo_migrate\Model\JoinModel|null
   */
  private ?JoinModel $join;

  /**
   * The special type model.
   *
   * @var \Drupal\labdoo_migrate\Model\SpecialTypeModel|null
   */
  private ?SpecialTypeModel $specialType;

  /**
   * FieldModel constructor.
   *
   * @param string $tableName
   *   The table name.
   * @param string $keyName
   *   The key name.
   * @param string $fieldName
   *   The field name.
   * @param string $expression
   *   The expression.
   * @param array $joins
   *   The joins.
   * @param bool $ignoreLangcode
   *   Ignores the langcode in the source query.
   * @param string $fieldAlias
   *   The field alias.
   * @param string $operator
   *   The operator.
   * @param string $filterPrefix
   *   Prefix for the query value.
   * @param string $filterSuffix
   *   Suffix for the query value.
   * @param mixed $defaultValue
   *   The default value.
   * @param \Drupal\labdoo_migrate\Model\DynamicParagraphModel|null $dynamicParagraph
   *   The dynamic paragraph model.
   * @param \Drupal\labdoo_migrate\Model\JoinModel|null $join
   *   The join model.
   * @param \Drupal\labdoo_migrate\Model\TaxonomyModel|null $taxonomy
   *   The taxonomy model.
   * @param \Drupal\labdoo_migrate\Model\SpecialTypeModel|null $specialType
   *   The special type model.
   */
  public function __construct(
    string $tableName,
    string $keyName,
    string $fieldName,
    string $expression = '',
    array $joins = [],
    bool $ignoreLangcode = FALSE,
    string $fieldAlias = '',
    string $operator = '=',
    string $filterPrefix = '',
    string $filterSuffix = '',
    $defaultValue = NULL,
    DynamicParagraphModel $dynamicParagraph = NULL,
    JoinModel $join = NULL,
    TaxonomyModel $taxonomy = NULL,
    SpecialTypeModel $specialType = NULL
  ) {

    $this->tableName = $tableName;
    $this->keyName = $keyName;
    $this->fieldName = $fieldName;
    $this->expression = $expression;
    $this->joins = $joins;
    $this->ignoreLangcode = $ignoreLangcode;
    $this->fieldAlias = $fieldAlias;
    $this->operator = $operator;
    $this->filterPrefix = $filterPrefix;
    $this->filterSuffix = $filterSuffix;
    $this->defaultValue = $defaultValue;
    $this->dynamicParagraph = $dynamicParagraph;
    $this->taxonomy = $taxonomy;
    $this->join = $join;
    $this->specialType = $specialType;
  }

  /**
   * Retrieves the table name.
   *
   * @return string
   *   Returns the table name.
   */
  public function getTableName(): string {

    return $this->tableName;
  }

  /**
   * Retrieves the key name.
   *
   * @return string
   *   Returns the key name.
   */
  public function getKeyName(): string {

    return $this->keyName;
  }

  /**
   * Retrieves the field name.
   *
   * @return string
   *   Returns the field name.
   */
  public function getFieldName(): string {

    return $this->fieldName;
  }

  /**
   * Retrieves the expression.
   *
   * @return string
   *   Returns the expression.
   */
  public function getExpression(): string {

    return $this->expression;
  }

  /**
   * Retrieves the joins.
   *
   * @return array
   *   Returns the joins.
   */
  public function getJoins(): array {

    return $this->joins;
  }

  /**
   * Retrieves the ignoreLangcode switch.
   *
   * @return bool
   *   The ignoreLangcode switch.
   */
  public function ignoreLangcode(): bool {

    return $this->ignoreLangcode;
  }

  /**
   * Retrieves the field alias.
   *
   * @return string
   *   Returns the field name.
   */
  public function getFieldAlias(): string {

    return $this->fieldAlias;
  }

  /**
   * Retrieves the operator.
   *
   * @return string
   *   Returns the operator.
   */
  public function getOperator(): string {

    return $this->operator;
  }

  /**
   * Retrieves the filter prefix.
   *
   * @return string
   *   Returns the filter prefix.
   */
  public function getFilterPrefix(): string {

    return $this->filterPrefix;
  }

  /**
   * Retrieves the filter suffix.
   *
   * @return string
   *   Returns the filter suffix.
   */
  public function getFilterSuffix(): string {

    return $this->filterSuffix;
  }

  /**
   * Retrieves the default value.
   *
   * @return mixed
   *   Returns the default value.
   */
  public function getDefaultValue() {

    return $this->defaultValue;
  }

  /**
   * Tells if the field is a dynamic paragraph.
   *
   * @return bool
   *   Returns TRUE if the field is a dynamic paragraph, otherwise FALSE.
   */
  public function isDynamicParagraph(): bool {

    return !is_null($this->dynamicParagraph);
  }

  /**
   * Retrieves the Dynamic Paragraph model.
   *
   * @return \Drupal\labdoo_migrate\Model\DynamicParagraphModel
   *   Returns the Dynamic Paragraph model.
   */
  public function getDynamicParagraph(): ?DynamicParagraphModel {

    return $this->dynamicParagraph;
  }

  /**
   * Tells if the Join model is defined.
   *
   * @return bool
   *   Returns TRUE if the Join model is defined, otherwise FALSE.
   */
  public function isJoin(): bool {

    return !is_null($this->join);
  }

  /**
   * Retrieves the Join model.
   *
   * @return \Drupal\labdoo_migrate\Model\JoinModel
   *   Returns the Join model.
   */
  public function getJoin(): ?JoinModel {

    return $this->join;
  }

  /**
   * Tells if the Taxonomy model is defined.
   *
   * @return bool
   *   Returns TRUE if the Taxonomy model is defined, otherwise FALSE.
   */
  public function isTaxonomy(): bool {

    return !is_null($this->taxonomy);
  }

  /**
   * Retrieves the Taxonomy model.
   *
   * @return \Drupal\labdoo_migrate\Model\TaxonomyModel
   *   Returns the Join model.
   */
  public function getTaxonomy(): ?TaxonomyModel {

    return $this->taxonomy;
  }

  /**
   * Tells if the Special Type model is defined.
   *
   * @return bool
   *   Returns TRUE if the Special Type model is defined, otherwise FALSE.
   */
  public function isSpecialType(): bool {

    return !is_null($this->specialType);
  }

  /**
   * Retrieves the Special Type model.
   *
   * @return \Drupal\labdoo_migrate\Model\SpecialTypeModel|null
   *   Returns the Special Type model.
   */
  public function getSpecialType(): ?SpecialTypeModel {

    return $this->specialType;
  }

}
