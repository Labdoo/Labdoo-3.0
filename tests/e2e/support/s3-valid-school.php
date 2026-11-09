<?php

require __DIR__ . '/assert-isolated.php';

use Drupal\labdoo_dootronics\Plugin\Validation\Constraint\DootronicS3RequiresEdoovillageConstraint;
use Drupal\node\Entity\Node;

$fixtures = json_decode(file_get_contents(\Drupal\Core\Site\Settings::get('labdoo_e2e_state') . '/integration-fixtures.json'), TRUE);
$source = Node::load($fixtures['nodes']['Laptop Main']['id']);
$missing_id = (int) \Drupal::database()->query('SELECT MAX(nid) FROM {node}')->fetchField() + 100;
$cases = ['empty' => [NULL, TRUE], 'dangling' => [$missing_id, TRUE], 'existing_school' => [$fixtures['nodes']['School']['id'], FALSE]];
foreach ($cases as $label => [$id, $expect_violation]) {
  $node = $source->createDuplicate();
  $node->set('field_dootronic_status', 'S3');
  $node->set('field_edoovillage_destination', $id);
  $found = FALSE;
  foreach ($node->validate() as $violation) {
    $found = $found || $violation->getConstraint() instanceof DootronicS3RequiresEdoovillageConstraint;
  }
  if ($found !== $expect_violation) {
    throw new RuntimeException('S3 school validation failed for ' . $label);
  }
}
echo 'Three S3 school reference validation cases passed without saving nodes.' . PHP_EOL;
