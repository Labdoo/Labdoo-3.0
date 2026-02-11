<?php

namespace Drupal\labdoo_scraper\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\labdoo_scraper\Service\ScraperService;

/**
 * Processes URLs discovered by the scraper.
 *
 * @QueueWorker(
 *   id = "labdoo_scraper_url_processor",
 *   title = @Translation("Labdoo Scraper URL Processor"),
 *   cron = {"time" = 60}
 * )
 */
class ScraperUrlProcessor extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The scraper service.
   *
   * @var \Drupal\labdoo_scraper\Service\ScraperService
   */
  protected $scraperService;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ScraperService $scraper_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->scraperService = $scraper_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('labdoo_scraper.scraper_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $recursive = !empty($data['recursive']);
    $this->scraperService->processDiscoveredUrl($data['source_url'], $data['discovered_url'], $recursive);
  }

}
