<?php

namespace Drupal\labdoo_statistics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides advanced statistics for Labdoo.
 */
class AdvancedStatisticsController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The common repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\CommonRepository
   */
  protected CommonRepository $commonRepository;

  /**
   * The country manager.
   *
   * @var \Drupal\Core\Locale\CountryManagerInterface
   */
  protected CountryManagerInterface $countryManager;

  /**
   * Constructs an AdvancedStatisticsController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository
   *   The common repository.
   * @param \Drupal\Core\Locale\CountryManagerInterface $countryManager
   *   The country manager.
   */
  public function __construct(Connection $database, CommonRepository $commonRepository, CountryManagerInterface $countryManager) {
    $this->database = $database;
    $this->commonRepository = $commonRepository;
    $this->countryManager = $countryManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('labdoo_common.repository.common'),
      $container->get('country_manager')
    );
  }

  /**
   * Renders the advanced statistics page.
   *
   * @return array
   *   A render array.
   */
  public function build(): array {
    $evolution_data = $this->getDootronicsEvolutionData();
    $status_data = $this->getDootronicsStatusData();
    $country_data = $this->getStudentsByCountryData();
    $edoovillage_evolution = $this->getEdoovillageEvolutionData();
    $hub_status_data = $this->getHubStatusData();
    $hub_evolution = $this->getHubEvolutionData();
    $hubs_by_country = $this->getHubsByCountryData();
    $top_hubs_activity = $this->getTopHubsByDootronicsData();
    $dootrip_km_data = $this->getDootripKmData();
    $task_type_data = $this->getTaskTypeData();
    $priority_data = $this->getTaskPriorityData();
    $task_status_data = $this->getTaskStatusData();
    $node_evolution_data = $this->getNodeEvolutionData();
    $team_activity_data = $this->getTeamActivityData();
    $content_type_data = $this->getContentTypeData();
    $comment_evolution_data = $this->getCommentEvolutionData();
    $top_dootronic_contributors = $this->getTopDootronicContributors();
    $top_commenters = $this->getTopCommenters();
    $tasks_by_team_data = $this->getTasksByTeamData();
    $wiki_stats = $this->getWikiStats();
    $dootrip_km_data = $this->getDootripKmData();
    $dootrip_evolution = $this->getDootripEvolutionData();
    $dootronic_device_data = $this->getDootronicsByDeviceTypeData();
    $dootronic_cpu_data = $this->getDootronicsByCpuData();
    $laptops_per_edoovillage = $this->getLaptopsPerEdoovillageData();
    $laptops_per_student = $this->getLaptopsPerStudentData();
    $wiki_activity = $this->getWikiActivityData();
    $top_wiki_editors = $this->getTopWikiEditorsData();
    $team_post_evolution = $this->getTeamPostEvolutionData();
    $top_team_post_contributors = $this->getTopTeamPostContributors();

    return [
      '#theme' => 'labdoo_advanced_statistics',
      '#evolution_data' => $evolution_data,
      '#status_data' => $status_data,
      '#country_data' => $country_data,
      '#edoovillage_evolution' => $edoovillage_evolution,
      '#hub_status_data' => $hub_status_data,
      '#hub_evolution' => $hub_evolution,
      '#hubs_by_country' => $hubs_by_country,
      '#top_hubs_activity' => $top_hubs_activity,
      '#dootrip_km_data' => $dootrip_km_data,
      '#dootrip_evolution' => $dootrip_evolution,
      '#task_type_data' => $task_type_data,
      '#priority_data' => $priority_data,
      '#task_status_data' => $task_status_data,
      '#node_evolution_data' => $node_evolution_data,
      '#team_activity_data' => $team_activity_data,
      '#content_type_data' => $content_type_data,
      '#comment_evolution_data' => $comment_evolution_data,
      '#top_dootronic_contributors' => $top_dootronic_contributors,
      '#top_commenters' => $top_commenters,
      '#tasks_by_team_data' => $tasks_by_team_data,
      '#wiki_stats' => $wiki_stats,
      '#dootronic_device_data' => $dootronic_device_data,
      '#dootronic_cpu_data' => $dootronic_cpu_data,
      '#laptops_per_edoovillage' => $laptops_per_edoovillage,
      '#laptops_per_student' => $laptops_per_student,
      '#wiki_activity' => $wiki_activity,
      '#top_wiki_editors' => $top_wiki_editors,
      '#team_post_evolution' => $team_post_evolution,
      '#top_team_post_contributors' => $top_team_post_contributors,
      '#platform_uptime' => $this->getPlatformUptime(),
      '#attached' => [
        'library' => [
          'labdoo_statistics/advanced_statistics',
        ],
        'drupalSettings' => [
          'labdoo_statistics' => [
            'evolution_data' => $evolution_data,
            'status_data' => $status_data,
            'country_data' => $country_data,
            'edoovillage_evolution' => $edoovillage_evolution,
            'hub_status_data' => $hub_status_data,
            'hub_evolution' => $hub_evolution,
            'hubs_by_country' => $hubs_by_country,
            'top_hubs_activity' => $top_hubs_activity,
            'dootrip_km_data' => $dootrip_km_data,
            'dootrip_evolution' => $dootrip_evolution,
            'task_type_data' => $task_type_data,
            'priority_data' => $priority_data,
            'task_status_data' => $task_status_data,
            'node_evolution_data' => $node_evolution_data,
            'team_activity_data' => $team_activity_data,
            'content_type_data' => $content_type_data,
            'comment_evolution_data' => $comment_evolution_data,
            'top_dootronic_contributors' => $top_dootronic_contributors,
            'top_commenters' => $top_commenters,
            'tasks_by_team_data' => $tasks_by_team_data,
            'dootronic_device_data' => $dootronic_device_data,
            'dootronic_cpu_data' => $dootronic_cpu_data,
            'laptops_per_edoovillage' => $laptops_per_edoovillage,
            'laptops_per_student' => $laptops_per_student,
            'wiki_activity' => $wiki_activity,
            'top_wiki_editors' => $top_wiki_editors,
            'team_post_evolution' => $team_post_evolution,
            'top_team_post_contributors' => $top_team_post_contributors,
          ],
        ],
      ],
    ];
  }

  /**
   * Gets Dootronics by device type distribution.
   */
  protected function getDootronicsByDeviceTypeData(): array {
    $query = $this->database->select('node__field_device_type', 'ndt');
    $query->fields('ndt', ['field_device_type_value']);
    $query->addExpression('COUNT(*)', 'count');
    $query->condition('ndt.bundle', 'dootronic');
    $query->groupBy('field_device_type_value');
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = ucfirst(str_replace('_', ' ', $row->field_device_type_value));
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets Dootronics by CPU type distribution.
   */
  protected function getDootronicsByCpuData(): array {
    $query = $this->database->select('node__field_cpu_type', 'nct');
    $query->fields('nct', ['field_cpu_type_value']);
    $query->addExpression('COUNT(*)', 'count');
    $query->condition('nct.bundle', 'dootronic');
    $query->groupBy('field_cpu_type_value');
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = $row->field_cpu_type_value;
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets task priority distribution (using Edoovillage semaphore as a proxy).
   */
  protected function getTaskPriorityData(): array {
    $query = $this->database->select('node__field_semaphore', 'nfs');
    $query->fields('nfs', ['field_semaphore_value']);
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('field_semaphore_value');
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    
    // Define the desired order for the semaphore.
    $order = ['red', 'yellow', 'green'];
    $ordered_results = [];
    foreach ($results as $row) {
      $ordered_results[$row->field_semaphore_value] = (int) $row->count;
    }

    foreach ($order as $key) {
      if (isset($ordered_results[$key])) {
        $data['labels'][] = ucfirst($key);
        $data['values'][] = $ordered_results[$key];
      }
    }
    
    return $data;
  }

  /**
   * Gets task status distribution (using Edoovillage status as a proxy).
   */
  protected function getTaskStatusData(): array {
    $query = $this->database->select('node__field_status', 'nfs');
    $query->condition('bundle', 'edoovillage');
    $query->fields('nfs', ['field_status_value']);
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('field_status_value');
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = ucfirst($row->field_status_value);
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets general node creation evolution.
   */
  protected function getNodeEvolutionData(): array {
    $data = ['labels' => [], 'values' => []];
    
    $last_created = $this->database->select('node_field_data', 'n')
      ->fields('n', ['created'])
      ->condition('type', 'gallery', '<>')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
      
    $reference_date = $last_created ? (new \DateTime())->setTimestamp((int) $last_created) : new \DateTime();
    $reference_date->modify('first day of this month');

    for ($i = 11; $i >= 0; $i--) {
      $date = (clone $reference_date)->modify("-$i months");
      $start = $date->getTimestamp();
      $end = (clone $date)->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();

      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.created', [$start, $end], 'BETWEEN');
      $query->condition('n.type', 'gallery', '<>');
      $count = $query->countQuery()->execute()->fetchField();

      $data['labels'][] = $date->format('M Y');
      $data['values'][] = (int) $count;
    }
    return $data;
  }

  /**
   * Gets task activity by team.
   */
  protected function getTeamActivityData(): array {
    $query = $this->database->select('node__field_team', 'nt');
    $query->join('node_field_data', 'n', 'nt.field_team_target_id = n.nid');
    $query->fields('n', ['title']);
    $query->addExpression('COUNT(nt.entity_id)', 'task_count');
    $query->groupBy('n.title');
    $query->orderBy('task_count', 'DESC');
    $query->range(0, 5);
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = $row->title;
      $data['values'][] = (int) $row->task_count;
    }
    return $data;
  }

  /**
   * Gets content type composition.
   */
  protected function getContentTypeData(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['type']);
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('type');
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    
    // Sort results by count descending so small types are still visible in the list but big ones are at the top
    usort($results, function($a, $b) {
      return $b->count <=> $a->count;
    });

    foreach ($results as $row) {
      $data['labels'][] = ucfirst(str_replace('_', ' ', $row->type));
      $data['values'][] = (int) $row->count;
    }

    // Add Wiki pages to composition
    $wiki_count = $this->database->select('mini_wiki_page_field_data', 'w')
      ->countQuery()->execute()->fetchField();
    if ($wiki_count > 0) {
      $data['labels'][] = $this->t('Wiki Pages');
      $data['values'][] = (int) $wiki_count;
    }

    // Re-sort with Wiki Pages included
    $combined = [];
    foreach ($data['labels'] as $i => $label) {
      $combined[] = ['label' => $label, 'value' => $data['values'][$i]];
    }
    usort($combined, function($a, $b) {
      return $b['value'] <=> $a['value'];
    });

    $data = ['labels' => [], 'values' => []];
    foreach ($combined as $item) {
      $data['labels'][] = $item['label'];
      $data['values'][] = $item['value'];
    }

    return $data;
  }

  /**
   * Gets task type distribution (using Action types).
   */
  protected function getTaskTypeData(): array {
    $query = $this->database->select('node__field_action_type', 'nft');
    $query->fields('nft', ['field_action_type_value']);
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('field_action_type_value');
    
    $results = $query->execute()->fetchAll();
    
    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = ucfirst($row->field_action_type_value);
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Calculates platform uptime in years, months and days.
   */
  protected function getPlatformUptime(): array|TranslatableMarkup {
    $min_created = $this->database->select('users_field_data', 'u')
      ->fields('u', ['created'])
      ->condition('uid', 1)
      ->execute()
      ->fetchField();

    if (!$min_created) {
      $min_created = $this->database->select('node_field_data', 'n')
        ->addExpression('MIN(created)')
        ->execute()
        ->fetchField();
    }

    if (!$min_created) {
      return $this->t('Unknown');
    }

    $start_date = new \DateTime();
    $start_date->setTimestamp((int) $min_created);
    $now = new \DateTime();
    $interval = $start_date->diff($now);

    return [
      'years' => $interval->y,
      'months' => $interval->m,
      'days' => $interval->d,
      'total_days' => $interval->days,
    ];
  }

  /**
   * Gets Dootronics registration evolution for the last 12 months with activity.
   */
  protected function getDootronicsEvolutionData(): array {
    $data = ['labels' => [], 'values' => []];
    
    // Find last activity to make the chart relevant.
    $last_created = $this->database->select('node_field_data', 'n')
      ->fields('n', ['created'])
      ->condition('type', 'dootronic')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    
    $reference_date = $last_created ? (new \DateTime())->setTimestamp((int) $last_created) : new \DateTime();
    // Always show at least until the first day of that month.
    $reference_date->modify('first day of this month');

    for ($i = 11; $i >= 0; $i--) {
      $date = (clone $reference_date)->modify("-$i months");
      $start = $date->getTimestamp();
      $end = (clone $date)->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();
      
      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'dootronic');
      $query->condition('n.created', [$start, $end], 'BETWEEN');
      $count = $query->countQuery()->execute()->fetchField();
      
      $data['labels'][] = $date->format('M Y');
      $data['values'][] = (int) $count;
    }
    return $data;
  }

  /**
   * Gets Dootronics distribution by status.
   */
  protected function getDootronicsStatusData(): array {
    $statuses = [
      'S1' => $this->t('S1: Donated'),
      'S2' => $this->t('S2: Prepared'),
      'S3' => $this->t('S3: In Transit'),
      'S4' => $this->t('S4: Delivered'),
    ];
    
    $data = ['labels' => [], 'values' => []];
    foreach ($statuses as $code => $label) {
      $count = $this->commonRepository->getDootronicsCountByStatus($code);
      $data['labels'][] = (string) $label;
      $data['values'][] = $count;
    }
    return $data;
  }

  /**
   * Gets students count by country (Top 10).
   */
  protected function getStudentsByCountryData(): array {
    $query = $this->database->select('node__field_country', 'nfc');
    $query->join('node__field_number_of_students', 'nfs', 'nfc.entity_id = nfs.entity_id');
    $query->fields('nfc', ['field_country_value']);
    $query->addExpression('SUM(nfs.field_number_of_students_value)', 'total_students');
    $query->condition('nfc.bundle', 'edoovillage');
    $query->groupBy('nfc.field_country_value');
    $query->orderBy('total_students', 'DESC');
    $query->range(0, 10);
    
    $results = $query->execute()->fetchAll();
    
    $countries = $this->countryManager->getList();
    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $country_code = strtoupper($row->field_country_value);
      $data['labels'][] = isset($countries[$country_code]) ? (string) $countries[$country_code] : $country_code;
      $data['values'][] = (int) $row->total_students;
    }
    return $data;
  }

  /**
   * Gets comment evolution for the last 12 months.
   */
  protected function getCommentEvolutionData(): array {
    $data = ['labels' => [], 'values' => []];
    
    $last_created = $this->database->select('comment_field_data', 'c')
      ->fields('c', ['created'])
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
      
    $reference_date = $last_created ? (new \DateTime())->setTimestamp((int) $last_created) : new \DateTime();
    $reference_date->modify('first day of this month');

    for ($i = 11; $i >= 0; $i--) {
      $date = (clone $reference_date)->modify("-$i months");
      $start = $date->getTimestamp();
      $end = (clone $date)->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();

      $query = $this->database->select('comment_field_data', 'c');
      $query->condition('c.created', [$start, $end], 'BETWEEN');
      $count = $query->countQuery()->execute()->fetchField();

      $data['labels'][] = $date->format('M Y');
      $data['values'][] = (int) $count;
    }
    return $data;
  }

  /**
   * Gets Dootrip kilometers data (Total and evolution).
   */
  protected function getDootripKmData(): array {
    $query = $this->database->select('node__field_distance_dootrip', 'nfd');
    $query->addExpression('SUM(field_distance_dootrip_value)');
    $total_km = $query->execute()->fetchField() ?: 0;

    $evolution = ['labels' => [], 'values' => []];
    for ($i = 4; $i >= 0; $i--) {
      $year = (int) date('Y') - $i;
      $start = mktime(0, 0, 0, 1, 1, (int) $year);
      $end = mktime(23, 59, 59, 12, 31, (int) $year);

      $query = $this->database->select('node__field_distance_dootrip', 'nfd');
      $query->join('node_field_data', 'n', 'nfd.entity_id = n.nid');
      $query->condition('n.created', [$start, $end], 'BETWEEN');
      $query->addExpression('SUM(nfd.field_distance_dootrip_value)', 'yearly_km');
      $yearly_km = $query->execute()->fetchField() ?: 0;

      $evolution['labels'][] = (string) $year;
      $evolution['values'][] = (float) $yearly_km;
    }

    return [
      'total_km' => $this->commonRepository->formatNumber((float) $total_km, 2),
      'evolution' => $evolution,
    ];
  }

  /**
   * Gets Hubs distribution by status.
   */
  protected function getHubStatusData(): array {
    $query = $this->database->select('node__field_hub_status', 'nfs');
    $query->fields('nfs', ['field_hub_status_value']);
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('field_hub_status_value');
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = ucfirst($row->field_hub_status_value);
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets Hub registration evolution for the last 12 months.
   */
  protected function getHubEvolutionData(): array {
    $data = ['labels' => [], 'values' => []];
    
    $last_created = $this->database->select('node_field_data', 'n')
      ->fields('n', ['created'])
      ->condition('type', 'hub')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
      
    $reference_date = $last_created ? (new \DateTime())->setTimestamp((int) $last_created) : new \DateTime();
    $reference_date->modify('first day of this month');

    for ($i = 11; $i >= 0; $i--) {
      $date = (clone $reference_date)->modify("-$i months");
      $start = $date->getTimestamp();
      $end = (clone $date)->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();

      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'hub');
      $query->condition('n.created', [$start, $end], 'BETWEEN');
      $count = $query->countQuery()->execute()->fetchField();

      $data['labels'][] = $date->format('M Y');
      $data['values'][] = (int) $count;
    }
    return $data;
  }

  /**
   * Gets Hubs distribution by country (Top 5).
   */
  protected function getHubsByCountryData(): array {
    $query = $this->database->select('node__field_country', 'nfc');
    $query->fields('nfc', ['field_country_value']);
    $query->addExpression('COUNT(*)', 'count');
    $query->condition('nfc.bundle', 'hub');
    $query->groupBy('nfc.field_country_value');
    $query->orderBy('count', 'DESC');
    $query->range(0, 5);

    $results = $query->execute()->fetchAll();

    $countries = $this->countryManager->getList();
    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $country_code = strtoupper($row->field_country_value);
      $data['labels'][] = isset($countries[$country_code]) ? (string) $countries[$country_code] : $country_code;
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets Top 5 Hubs by processed Dootronics.
   */
  protected function getTopHubsByDootronicsData(): array {
    $query = $this->database->select('node__field_hub', 'nfh');
    $query->join('node_field_data', 'n', 'nfh.field_hub_target_id = n.nid');
    $query->fields('n', ['title']);
    $query->addExpression('COUNT(nfh.entity_id)', 'dootronic_count');
    $query->groupBy('n.title');
    $query->orderBy('dootronic_count', 'DESC');
    $query->range(0, 4);

    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = $row->title;
      $data['values'][] = (int) $row->dootronic_count;
    }
    return $data;
  }

  /**
   * Gets Edoovillage registration evolution.
   */
  protected function getEdoovillageEvolutionData(): array {
    $data = ['labels' => [], 'values' => []];
    
    $last_created = $this->database->select('node_field_data', 'n')
      ->fields('n', ['created'])
      ->condition('type', 'edoovillage')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
      
    $reference_date = $last_created ? (new \DateTime())->setTimestamp((int) $last_created) : new \DateTime();
    $reference_date->modify('first day of this month');

    for ($i = 11; $i >= 0; $i--) {
      $date = (clone $reference_date)->modify("-$i months");
      $start = $date->getTimestamp();
      $end = (clone $date)->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();

      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'edoovillage');
      $query->condition('n.created', [$start, $end], 'BETWEEN');
      $count = $query->countQuery()->execute()->fetchField();

      $data['labels'][] = $date->format('M Y');
      $data['values'][] = (int) $count;
    }
    return $data;
  }

  /**
   * Gets Dootrip registration evolution (last 12 months with activity).
   */
  protected function getDootripEvolutionData(): array {
    $data = ['labels' => [], 'values' => []];
    
    $last_created = $this->database->select('node_field_data', 'n')
      ->fields('n', ['created'])
      ->condition('type', 'dootrip')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
      
    $reference_date = $last_created ? (new \DateTime())->setTimestamp((int) $last_created) : new \DateTime();
    $reference_date->modify('first day of this month');

    for ($i = 11; $i >= 0; $i--) {
      $date = (clone $reference_date)->modify("-$i months");
      $start = $date->getTimestamp();
      $end = (clone $date)->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();

      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'dootrip');
      $query->condition('n.created', [$start, $end], 'BETWEEN');
      $count = $query->countQuery()->execute()->fetchField();

      $data['labels'][] = $date->format('M Y');
      $data['values'][] = (int) $count;
    }
    return $data;
  }

  /**
   * Gets top 5 Dootronic contributors.
   */
  protected function getTopDootronicContributors(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->join('users_field_data', 'u', 'n.uid = u.uid');
    $query->condition('n.type', 'dootronic');
    $query->condition('n.uid', 0, '<>');
    $query->fields('u', ['name']);
    $query->addExpression('COUNT(n.nid)', 'count');
    $query->groupBy('u.name');
    $query->orderBy('count', 'DESC');
    $query->range(0, 5);
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = $row->name ?: (string) $this->t('Anonymous');
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets top task creators.
   */
  protected function getTopTaskCreators(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->join('users_field_data', 'u', 'n.uid = u.uid');
    $query->fields('u', ['name']);
    $query->addExpression('COUNT(n.nid)', 'count');
    $query->condition('n.type', 'task_team');
    $query->groupBy('u.name');
    $query->orderBy('count', 'DESC');
    $query->range(0, 5);

    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = $row->name ?: (string) $this->t('Anonymous');
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets top commenters.
   */
  protected function getTopCommenters(): array {
    $query = $this->database->select('comment_field_data', 'c');
    $query->join('users_field_data', 'u', 'c.uid = u.uid');
    $query->fields('u', ['name']);
    $query->addExpression('COUNT(c.cid)', 'count');
    $query->groupBy('u.name');
    $query->orderBy('count', 'DESC');
    $query->range(0, 5);

    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = $row->name ?: (string) $this->t('Anonymous');
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets task distribution by team.
   */
  protected function getTasksByTeamData(): array {
    $query = $this->database->select('node__field_team', 'nt');
    $query->fields('nt', ['field_team_target_id']);
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('field_team_target_id');
    $query->orderBy('count', 'DESC');
    $query->range(0, 10);

    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $data['labels'][] = "Team " . $row->field_team_target_id;
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets wiki and community post statistics.
   */
  protected function getWikiStats(): array {
    $wiki_count = $this->database->select('mini_wiki_page_field_data', 'w')
      ->countQuery()
      ->execute()
      ->fetchField();

    $team_posts = $this->database->select('node_field_data', 'n')
      ->condition('type', 'team_post')
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'wiki_pages' => (int) $wiki_count,
      'team_posts' => (int) $team_posts,
    ];
  }

  /**
   * Gets laptops distribution by Edoovillage (Top 10).
   */
  protected function getLaptopsPerEdoovillageData(): array {
    $query = $this->database->select('node__field_edoovillage_destination', 'ned');
    $query->join('node_field_data', 'n', 'ned.field_edoovillage_destination_target_id = n.nid');
    $query->fields('n', ['title']);
    $query->addExpression('COUNT(ned.entity_id)', 'laptop_count');
    $query->groupBy('n.title');
    $query->orderBy('laptop_count', 'DESC');
    $query->range(0, 10);
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $title = $row->title;
      if (str_contains($title, ':')) {
        $title = explode(':', $title)[0];
      }
      $data['labels'][] = $title;
      $data['values'][] = (int) $row->laptop_count;
    }
    return $data;
  }

  /**
   * Gets laptops per student ratio (Top 10 schools).
   */
  protected function getLaptopsPerStudentData(): array {
    // Subquery to count laptops per school
    $subquery = $this->database->select('node__field_edoovillage_destination', 'ned');
    $subquery->fields('ned', ['field_edoovillage_destination_target_id']);
    $subquery->addExpression('COUNT(ned.entity_id)', 'laptop_count');
    $subquery->groupBy('field_edoovillage_destination_target_id');

    $query = $this->database->select('node_field_data', 'n');
    $query->join('node__field_number_of_students', 'nfs', 'n.nid = nfs.entity_id');
    $query->join($subquery, 'laptops', 'n.nid = laptops.field_edoovillage_destination_target_id');
    $query->fields('n', ['title']);
    $query->addExpression('laptops.laptop_count / nfs.field_number_of_students_value', 'ratio');
    $query->condition('nfs.field_number_of_students_value', 0, '>');
    $query->orderBy('ratio', 'DESC');
    $query->range(0, 10);
    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      $title = $row->title;
      if (str_contains($title, ':')) {
        $title = explode(':', $title)[0];
      }
      $data['labels'][] = $title;
      $data['values'][] = round((float) $row->ratio, 4);
    }
    return $data;
  }

  /**
   * Gets wiki activity (Last 12 months with activity).
   */
  protected function getWikiActivityData(): array {
    $data = ['labels' => [], 'values' => []];
    
    $last_created = $this->database->select('mini_wiki_page_field_data', 'w')
      ->fields('w', ['created'])
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
      
    $reference_date = $last_created ? (new \DateTime())->setTimestamp((int) $last_created) : new \DateTime();
    $reference_date->modify('first day of this month');

    for ($i = 11; $i >= 0; $i--) {
      $date = (clone $reference_date)->modify("-$i months");
      $start = $date->getTimestamp();
      $end = (clone $date)->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();

      $query = $this->database->select('mini_wiki_page_field_data', 'w');
      $query->condition('created', [$start, $end], 'BETWEEN');
      $count = $query->countQuery()->execute()->fetchField();

      $data['labels'][] = $date->format('M Y');
      $data['values'][] = (int) $count;
    }
    return $data;
  }

  /**
   * Gets top 5 most active wiki editors.
   */
  protected function getTopWikiEditorsData(): array {
    $query = $this->database->select('mini_wiki_page_field_data', 'w');
    $query->leftJoin('users_field_data', 'u', 'w.uid = u.uid');
    $query->fields('u', ['name']);
    $query->fields('w', ['uid']);
    $query->addExpression('COUNT(w.id)', 'count');
    $query->groupBy('u.name');
    $query->groupBy('w.uid');
    $query->orderBy('count', 'DESC');
    $query->range(0, 5);

    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      if ($row->name) {
        $label = $row->name;
      }
      elseif ($row->uid) {
        $label = (string) $this->t('User @uid', ['@uid' => $row->uid]);
      }
      else {
        $label = (string) $this->t('Anonymous');
      }
      $data['labels'][] = $label;
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

  /**
   * Gets Team Post registration evolution (Last 12 months with activity).
   */
  protected function getTeamPostEvolutionData(): array {
    $data = ['labels' => [], 'values' => []];
    
    $last_created = $this->database->select('node_field_data', 'n')
      ->fields('n', ['created'])
      ->condition('type', 'team_post')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
      
    $reference_date = $last_created ? (new \DateTime())->setTimestamp((int) $last_created) : new \DateTime();
    $reference_date->modify('first day of this month');

    for ($i = 11; $i >= 0; $i--) {
      $date = (clone $reference_date)->modify("-$i months");
      $start = $date->getTimestamp();
      $end = (clone $date)->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();

      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'team_post');
      $query->condition('n.created', [$start, $end], 'BETWEEN');
      $count = $query->countQuery()->execute()->fetchField();

      $data['labels'][] = $date->format('M Y');
      $data['values'][] = (int) $count;
    }
    return $data;
  }

  /**
   * Gets Top 5 Team Post contributors.
   */
  protected function getTopTeamPostContributors(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin('users_field_data', 'u', 'n.uid = u.uid');
    $query->fields('u', ['name']);
    $query->fields('n', ['uid']);
    $query->condition('n.type', 'team_post');
    $query->condition('n.uid', 0, '<>');
    $query->addExpression('COUNT(n.nid)', 'count');
    $query->groupBy('u.name');
    $query->groupBy('n.uid');
    $query->orderBy('count', 'DESC');
    $query->range(0, 5);

    $results = $query->execute()->fetchAll();

    $data = ['labels' => [], 'values' => []];
    foreach ($results as $row) {
      if ($row->name) {
        $label = $row->name;
      }
      elseif ($row->uid) {
        $label = (string) $this->t('User @uid', ['@uid' => $row->uid]);
      }
      else {
        $label = (string) $this->t('Anonymous');
      }
      $data['labels'][] = $label;
      $data['values'][] = (int) $row->count;
    }
    return $data;
  }

}
