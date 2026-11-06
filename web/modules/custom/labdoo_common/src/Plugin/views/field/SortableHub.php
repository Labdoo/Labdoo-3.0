<?php

namespace Drupal\labdoo_common\Plugin\views\field;

use Drupal\views\Plugin\views\field\EntityField;

/**
 * Sorts grouped hub references without joining a row for every reference.
 *
 * @ViewsField("labdoo_sortable_hub")
 */
class SortableHub extends EntityField {

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function clickSort($order) {
    if (!$this->multiple || !$this->options['group_rows']) {
      parent::clickSort($order);
      return;
    }
    $base_table = $this->view->storage->get('base_table');
    $base_alias = $this->query->ensureTable($base_table, $this->relationship);
    // One scalar per source node keeps pagination and grouped labels intact.
    // Use the first hub title alphabetically, in its base language.
    $expression = <<<SQL
(SELECT MIN(hub_titles.title)
FROM {node__field_hub} hub_refs
INNER JOIN {node_field_data} hub_titles
ON hub_titles.nid = hub_refs.field_hub_target_id
AND hub_titles.default_langcode = 1
WHERE hub_refs.entity_id = {$base_alias}.nid AND hub_refs.deleted = 0)
SQL;
    $this->query->addOrderBy(NULL, $expression, $order, 'labdoo_hub_title_sort');
  }

}
