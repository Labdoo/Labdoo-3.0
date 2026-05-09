<?php

namespace Drupal\labdoo_common\EventSubscriber;

use Drupal\Core\Queue\QueueFactory;
use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for handling cache invalidation events.
 */
class CacheEventSubscriber implements EventSubscriberInterface {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * Constructs a new CacheEventSubscriber.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(QueueFactory $queueFactory) {
    $this->queueFactory = $queueFactory;
  }

  /**
   * Enqueues the received cache tags for invalidation.
   *
   * @param \Drupal\labdoo_common\Event\InvalidateCacheTagsEvent $event
   *   The event.
   *
   * @return void
   */
  public function onInvalidatedTags(InvalidateCacheTagsEvent $event): void {
    $cacheTags = $event->getCacheTags();
    if (empty($cacheTags)) {
      return;
    }

    $queue = $this->queueFactory->get('labdoo_common_cache_invalidation');
    $queue->createItem(['tags' => $cacheTags]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[InvalidateCacheTagsEvent::EVENT_NAME][] = ['onInvalidatedTags'];

    return $events;
  }

}
