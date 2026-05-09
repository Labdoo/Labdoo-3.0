<?php

namespace Drupal\labdoo_user\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for user dashboard.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class UserController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The common repository service.
   *
   * @var \Drupal\labdoo_common\Service\Repository\CommonRepository
   */
  protected CommonRepository $commonRepository;

  /**
   * Constructs a UserController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository
   *   The common repository service.
   */
  public function __construct(Connection $database, CommonRepository $commonRepository) {
    $this->database = $database;
    $this->commonRepository = $commonRepository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('labdoo_common.repository.common')
    );
  }

  /**
   * User page redirect.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the login or user profile.
   */
  public function userPage(): RedirectResponse {
    $currentUser = \Drupal::currentUser();
    if ($currentUser->isAnonymous()) {
      return $this->redirect('user.login');
    }
    return $this->redirect('entity.user.canonical', ['user' => $currentUser->id()]);
  }

  /**
   * Redirects to the user dashboard.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the user dashboard.
   */
  public function dashboardRedirect(): RedirectResponse {
    $userId = \Drupal::currentUser()->id();
    return $this->redirect('labdoo_user.dashboard', ['user' => $userId]);
  }

  /**
   * Displays the user dashboard.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return array
   *   A render array for the dashboard.
   */
  public function dashboard(User $user): array {
    if (!$user) {
      return [
        '#markup' => $this->t('User not found.'),
      ];
    }

    // Get the username for display
    $username = $user->getAccountName();
    // Common tasks
    $common_tasks = [
      [
        'image' => '/themes/custom/labdoo/img/act-1-alpha.png',
        'url' => '/content/about-dootronics',
        'title' => $this->t('I want to contribute and tag a laptop'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-1-alpha.png',
        'url' => '/content/how-sanitize-laptop',
        'title' => $this->t('I want to sanitize a laptop'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-2-alpha.png',
        'url' => '/content/dootrip-system',
        'title' => $this->t('I want to contribute a trip (dootrip) to carry a laptop to a destination school (edoovillage)'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-6-alpha.png',
        'url' => '/content/finding-and-contacting-other-labdoo-users',
        'title' => $this->t('I want to communicate with another labdooer'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-7-alpha.png',
        'url' => '/content/about-teams',
        'title' => $this->t('I want to join a Labdoo Team'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-4-alpha.png',
        'url' => '/content/labdoo-social-network-how-it-works',
        'title' => $this->t('I want to learn more in detail the features offered by the Labdoo Aid Social Network'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-5-alpha.png',
        'url' => '/content/values-philosophy-and-principles-labdoo-project',
        'title' => $this->t('I want to learn about the Labdoo Project values and philosophy'),
      ],
    ];

    // Advanced tasks
    $advanced_tasks = [
      [
        'image' => '/themes/custom/labdoo/img/act-3-alpha.png',
        'url' => '/content/about-hubs',
        'title' => $this->t('I want to create my own Labdoo Hub'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-4-alpha.png',
        'url' => '/content/managing-edoovillages',
        'title' => $this->t('I want to create one school project (edoovillage)'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-8-alpha.png',
        'url' => '/content/labdoo-wiki',
        'title' => $this->t('I want to contribute to the Labdoo Wiki'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-1-alpha.png',
        'url' => '/content/wiki-translations',
        'title' => $this->t('I want to help translate Labdoo to a language I am good at'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-2-alpha.png',
        'url' => '/content/newsletters',
        'title' => $this->t('I want to create my own newsletter to bring awareness to my local community'),
      ],
      [
        'image' => '/themes/custom/labdoo/img/act-3-alpha.png',
        'url' => '/content/creating-and-managing-labdoo-events',
        'title' => $this->t('I want to organize a Labdoo event (such as a sanitation event, a workshop or a conference)'),
      ],
    ];

    return [
      '#theme' => 'labdoo_user_dashboard',
      '#username' => $username,
      '#common_tasks' => $common_tasks,
      '#advanced_tasks' => $advanced_tasks,
    ];
  }

  /**
   * Redirects to the user metrics.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the user metrics.
   */
  public function metricsRedirect(): RedirectResponse {
    $userId = \Drupal::currentUser()->id();
    return $this->redirect('labdoo_user.metrics', ['user' => $userId]);
  }

  /**
   * Displays the user metrics.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return array
   *   A render array for the metrics page.
   */
  public function metrics(User $user): array {
    if (!$user) {
      return [
        '#markup' => $this->t('User not found.'),
      ];
    }

    $userId = $user->id();

    // Calculate metrics
    $numDoojects = $this->commonRepository->getUserDoojectsCount($userId);
    $numDootrips = $this->commonRepository->getUserDootripsCount($userId);
    $numEdoovillages = $this->commonRepository->getUserEdoovillagesCount($userId);
    $numHubs = $this->commonRepository->getUserHubsCount($userId);
    $numWikis = $this->commonRepository->getUserWikisCount($userId);

    // Compute carbon footprint (constants are taken from the Labdoo carbon footprint calculator)
    $footprintCarbon = $numDoojects * 18.59;
    $footprintTrees = $footprintCarbon / 18.59 * 2;
    $footprintGas = $footprintCarbon / 18.59 * 26.5;
    $footprintPlastic = $footprintCarbon / 18.59 * 59;
    $footprintWater = $footprintCarbon / 18.59 * 1500;
    $footprintAluminum = $footprintCarbon / 18.59 * 270;
    $footprintGold = $footprintCarbon / 18.59 * 0.22;
    $footprintSilver = $footprintCarbon / 18.59 * 0.44;
    $footprintPalladium = $footprintCarbon / 18.59 * 0.08;
    $footprintCopper = $footprintCarbon / 18.59 * 0.3;
    $footprintCobalt = $footprintCarbon / 18.59 * 0.065;
    $footprintChemical = $footprintCarbon / 18.59 * 22;

    return [
      '#theme' => 'labdoo_user_metrics',
      '#user_id' => $userId,
      '#num_doojects' => $this->commonRepository->formatNumber($numDoojects),
      '#num_dootrips' => $this->commonRepository->formatNumber($numDootrips),
      '#num_edoovillages' => $this->commonRepository->formatNumber($numEdoovillages),
      '#num_hubs' => $this->commonRepository->formatNumber($numHubs),
      '#num_wikis' => $this->commonRepository->formatNumber($numWikis),
      '#footprint_carbon' => $this->commonRepository->formatNumber($footprintCarbon),
      '#footprint_trees' => $this->commonRepository->formatNumber($footprintTrees),
      '#footprint_gas' => $this->commonRepository->formatNumber($footprintGas),
      '#footprint_plastic' => $this->commonRepository->formatNumber($footprintPlastic),
      '#footprint_water' => $this->commonRepository->formatNumber($footprintWater),
      '#footprint_aluminum' => $this->commonRepository->formatNumber($footprintAluminum),
      '#footprint_gold' => $this->commonRepository->formatNumber($footprintGold),
      '#footprint_silver' => $this->commonRepository->formatNumber($footprintSilver),
      '#footprint_palladium' => $this->commonRepository->formatNumber($footprintPalladium),
      '#footprint_copper' => $this->commonRepository->formatNumber($footprintCopper),
      '#footprint_cobalt' => $this->commonRepository->formatNumber($footprintCobalt),
      '#footprint_chemical' => $this->commonRepository->formatNumber($footprintChemical),
    ];
  }

  /**
   * Displays the user roles.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return array
   *   A render array for the roles' page.
   */
  public function myRoles(User $user): array {
    if (!$user) {
      return [
        '#markup' => $this->t('User not found.'),
      ];
    }

    // Define all roles with their descriptions
    $all_roles = [
      'authenticated' => [
        'name' => 'Labdooer',
        'description' => $this->t('Provides the base functionality to carry out Labdoo mini-missions.'),
      ],
      'administrator' => [
        'name' => 'Administrator',
        'description' => $this->t('All access rights.'),
      ],
      'newsletter_manager' => [
        'name' => 'Newsletter manager',
        'description' => $this->t('Manage, create and publish newsletters to help bring awareness about your Labdoo activities.'),
      ],
      'hub_manager' => [
        'name' => 'Hub manager',
        'description' => $this->t('Manage a hub, organize hub inventory, upload pictures to your hub album.'),
      ],
      'edoovillage_manager' => [
        'name' => 'Edoovillage manager',
        'description' => $this->t('Create and manage edoovillages, upload pictures to your edoovillages albums.'),
      ],
      'superhub_manager' => [
        'name' => 'Superhub manager',
        'description' => $this->t('Create hubs and help manage Labdoo operations at a larger regional scale.'),
      ],
      'team_manager' => [
        'name' => 'Team manager',
        'description' => $this->t('Create new teams, manage memberships and all team related activities.'),
      ],
      'wiki_writer' => [
        'name' => 'Wiki writer',
        'description' => $this->t('Create and edit new wiki articles and books.'),
      ],
    ];

    // Get user roles
    $user_roles = $user->getRoles();

    // Prepare current roles and available roles
    $current_roles = [];
    $available_roles = [];

    foreach ($all_roles as $role_id => $role_info) {
      if (in_array($role_id, $user_roles)) {
        $current_roles[$role_id] = $role_info;
      } else {
        $available_roles[$role_id] = $role_info;
      }
    }

    // Build the render array using the Twig template
    return [
      '#theme' => 'labdoo_user_my_roles',
      '#current_roles' => array_values($current_roles),
      '#available_roles' => array_values($available_roles),
    ];
  }

  /**
   * Redirects to the user roles page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the user roles page.
   */
  public function myRolesRedirect(): RedirectResponse {
    $userId = \Drupal::currentUser()->id();

    return $this->redirect('labdoo_user.my_roles', ['user' => $userId]);
  }

}
