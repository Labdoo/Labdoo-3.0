<?php

namespace Drupal\labdoo_statistics\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_statistics\Constants;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Statistics block.
 *
 * @Block(
 *   id = "statistics_block_block",
 *   admin_label = @Translation("Statistics"),
 *   category = @Translation("Statistics"),
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class StatisticsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The statistics repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\CommonRepository
   */
  protected CommonRepository $commonRepository;

  /**
   * StatisticsBlock constructor.
   *
   * @param array $configuration
   *   The configuration array.
   * @param mixed $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository
   *   The common repository.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    CommonRepository $commonRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->commonRepository = $commonRepository;
  }

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    /** @var \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository */
    $commonRepository = $container->get('labdoo_common.repository.common');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $commonRepository
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $dootronicsTagged = $this->commonRepository
      ->getDootronicsCountByStatus();
    $dootronicsDelivered = $this->commonRepository
      ->getDootronicsCountByStatus('S4');
    $edoovillages = $this->commonRepository
      ->getEdooVillagesCount();
    $students = $this->commonRepository
      ->getStudentsCount();
    $hubs = $this->commonRepository
      ->getHubsCount();
    $co2saved = $this->commonRepository
      ->getCo2Saved($dootronicsDelivered);
    $countries = count(
      $this->commonRepository
        ->getActiveCountries('dootronic')
    );
    $dootronicsUrl = Url::fromRoute('view.dootronics_dashboard.page_1')->toString();
    $edoovillagesUrl = Url::fromRoute('view.edoovillages.page_1')->toString();
    $hubsUrl = Url::fromRoute('view.hubs_dashboard.page_1')->toString();

    return [
      '#theme' => 'statistics_block_block',
      '#dootronics_tagged' => $dootronicsTagged,
      '#dootronics_delivered' => $dootronicsDelivered,
      '#edoovillages' => $edoovillages,
      '#students' => $students,
      '#hubs' => $hubs,
      '#co2' => $co2saved,
      '#countries' => $countries,
      '#dootronics_url' => $dootronicsUrl,
      '#edoovillages_url' => $edoovillagesUrl,
      '#hubs_url' => $hubsUrl,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'tags' => Constants::CACHE_TAGS,
      ],
    ];
  }

}
