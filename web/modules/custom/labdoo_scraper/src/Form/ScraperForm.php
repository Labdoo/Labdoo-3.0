<?php

namespace Drupal\labdoo_scraper\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\labdoo_scraper\Service\ScraperService;
use Drupal\Core\Queue\QueueFactory;

/**
 * Form to start the scraper.
 */
class ScraperForm extends FormBase {

  /**
   * The scraper service.
   *
   * @var \Drupal\labdoo_scraper\Service\ScraperService
   */
  protected $scraperService;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Constructs a new ScraperForm object.
   */
  public function __construct(ScraperService $scraper_service, QueueFactory $queue_factory) {
    $this->scraperService = $scraper_service;
    $this->queueFactory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('labdoo_scraper.scraper_service'),
      $container->get('queue')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'labdoo_scraper_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL to Scrape'),
      '#description' => $this->t('Enter the URL you want to scrape for links.'),
      '#required' => TRUE,
    ];

    $form['recursive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Recursive Scrapping'),
      '#description' => $this->t('If checked, the scraper will recursively visit all discovered links on the same domain.'),
      '#default_value' => FALSE,
    ];

    $form['clear_existing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Clear previous results'),
      '#description' => $this->t('Delete all existing records in the scraper report before enqueuing new URLs.'),
      '#default_value' => FALSE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Scrapping'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $url = $form_state->getValue('url');
    $recursive = (bool) $form_state->getValue('recursive');
    $clear_existing = (bool) $form_state->getValue('clear_existing');

    if ($clear_existing) {
      \Drupal::database()->truncate('labdoo_scraper_results')->execute();
      $this->messenger()->addStatus($this->t('Previous results have been deleted.'));
    }

    $discovered_urls = $this->scraperService->scrapeUrl($url);

    if (empty($discovered_urls)) {
      $this->messenger()->addWarning($this->t('No URLs discovered at @url.', ['@url' => $url]));
      return;
    }

    $queue = $this->queueFactory->get('labdoo_scraper_url_processor');
    foreach ($discovered_urls as $discovered_url) {
      $queue->createItem([
        'source_url' => $url,
        'discovered_url' => $discovered_url,
        'recursive' => $recursive,
      ]);
    }

    $this->messenger()->addStatus($this->t('Discovered @count URLs. They have been added to the queue for processing.', ['@count' => count($discovered_urls)]));
  }

}
