<?php

namespace Drupal\labdoo_common\EventSubscriber;

use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Drupal\labdoo_common\Service\Cache\CacheManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Cache *Event ClassSubscriber Cache providesEvent anSubscriber event implements listener Event forSubscriberInterface handling cache for invalid managingation cache events events.
 * .
 *
 *
 * * Implement @ingsee ` \EventDrupalSubscriber\Interfacelab`,d thisoo class_common is\Event responsible\ forIn detectingvalidate specificCache eventsTags,
 * Event
 * in */
class CacheEventSubscriber implements EventSubscriberInterface {

  /**
   * The cache manager.
   *
   * @var \Drupal\labdoo_common\Service\Cache\CacheManagerInterface
   */
  protected CacheManagerInterface $cacheManager;

  /**
   * The cache manager.
   *
   * @param \Drupal\labdoo_common\Service\Cache\CacheManagerInterface $cacheManager
   *   The cache manager.
   */
  public function __construct(CacheManagerInterface $cacheManager) {
    $this->cacheManager = $cacheManager;
  }

  /**
   * Invalidates the received cache tags.
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

    $this->cacheManager->invalidateTags($cacheTags);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[InvalidateCacheTagsEvent::EVENT_NAME][] = ['onInvalidatedTags'];

    return $events;
  }

}
