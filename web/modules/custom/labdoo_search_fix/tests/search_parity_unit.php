<?php

require getenv('LABDOO_AUTOLOAD') ?: dirname(__DIR__, 5) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 5);
$checks = [];
foreach (['search_results', 'search_content'] as $search_id) {
  $search = Yaml::parseFile($root . '/config/sync/search_api_autocomplete.search.' . $search_id . '.yml');
  $checks[] = isset($search['suggester_settings']['indexed_results']);
  $checks[] = $search['suggester_limits']['indexed_results'] === 15;
  $checks[] = $search['options']['limit'] === 15;
  $checks[] = $search['options']['min_length'] === 3;
}

$view = Yaml::parseFile($root . '/config/sync/views.view.search_results.yml');
$fields = $view['display']['default']['display_options']['fields'];
$checks[] = $fields['title']['settings']['link_to_entity'] === TRUE;
$checks[] = $fields['nothing']['exclude'] === FALSE;
$checks[] = str_contains($fields['nothing']['alter']['text'], '{{ type }}');
$checks[] = $view['display']['page_1']['display_options']['path'] === 'search-results';
$suggester = file_get_contents($root . '/web/modules/custom/labdoo_search_fix/src/Plugin/search_api_autocomplete/suggester/IndexedResults.php');
$checks[] = str_contains($suggester, '$query->range(0, 5)');
$checks[] = str_contains($suggester, "setOption('skip result count', TRUE)");

if (in_array(FALSE, $checks, TRUE)) {
  fwrite(STDERR, "Search autocomplete does not match the V2 result behavior.\n");
  exit(1);
}

echo "Search autocomplete uses capped live results and the typed results page.\n";
