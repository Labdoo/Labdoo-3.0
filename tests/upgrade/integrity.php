<?php
require __DIR__ . '/../e2e/support/assert-isolated.php';
$phase = getenv('LABDOO_INTEGRITY_PHASE');
if (!in_array($phase, ['before', 'after'], TRUE)) throw new RuntimeException('Choose before or after');
$database = \Drupal::database();
$data = ['core' => \Drupal::VERSION, 'counts' => [], 'nodes' => []];
foreach (['node','node_field_data','node_revision','users','users_field_data','media','media_field_data','file_managed','paragraphs_item','paragraphs_item_field_data','taxonomy_term_data','mini_wiki_page','mini_wiki_page_revision','comment','comment_field_data','webform_submission','node__field_address','node__field_dootronic_id','node__field_edoovillage_id'] as $table) {
  if ($database->schema()->tableExists($table)) $data['counts'][$table] = (int) $database->select($table)->countQuery()->execute()->fetchField();
}
$query = $database->select('node', 'n')->fields('n', ['type'])->groupBy('type');
$query->addExpression('COUNT(*)', 'total');
foreach ($query->execute() as $row) $data['nodes'][$row->type] = (int) $row->total;
$state = \Drupal\Core\Site\Settings::get('labdoo_e2e_state');
file_put_contents($state . '/integrity-' . $phase . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
if ($phase === 'after') {
  $before = json_decode(file_get_contents($state . '/integrity-before.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  if ($before['counts'] !== $data['counts'] || $before['nodes'] !== $data['nodes']) throw new RuntimeException('Entity or field-row counts changed during database updates; inspect private integrity snapshots');
}
echo 'Entity integrity ' . $phase . ' captured on Drupal ' . \Drupal::VERSION . PHP_EOL;
