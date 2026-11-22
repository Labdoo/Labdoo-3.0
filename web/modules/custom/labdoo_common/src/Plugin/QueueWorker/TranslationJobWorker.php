<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_common\Service\TranslationJobs;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes only explicitly submitted administrative translation jobs.
 *
 * @QueueWorker(
 *   id = "labdoo_translation_jobs",
 *   title = @Translation("Labdoo translation jobs"),
 *   cron = {"time" = 300}
 * )
 */
final class TranslationJobWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private TranslationJobs $jobs) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('labdoo_common.translation_jobs'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (is_array($data) && is_string($data['job_id'] ?? NULL)) {
      $this->jobs->process($data['job_id']);
    }
  }

}
