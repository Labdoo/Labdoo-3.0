<?php

require getenv('LABDOO_AUTOLOAD') ?: dirname(__DIR__, 5) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$view = Yaml::parseFile(dirname(__DIR__, 5) . '/config/sync/views.view.search_inside_teams.yml');
$options = $view['display']['default']['display_options'];
$filters = $options['filters'];
$checks = [
  $view['id'] === 'search_inside_teams',
  $view['display']['page_1']['display_options']['path'] === 'teams-dashboard',
  $filters['combine']['fields'] === ['title' => 'title', 'body' => 'body'],
  array_keys($filters['type']['value']) === ['team_post', 'task_team'],
  $filters['field_team_target_id']['exposed'] === TRUE,
  $options['access']['type'] === 'role',
];

if (in_array(FALSE, $checks, TRUE)) {
  fwrite(STDERR, "Team search view configuration is incomplete.\n");
  exit(1);
}

echo "Team search view configuration is complete.\n";
