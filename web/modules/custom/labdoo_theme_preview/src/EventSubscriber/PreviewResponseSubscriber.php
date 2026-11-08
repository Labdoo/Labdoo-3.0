<?php

namespace Drupal\labdoo_theme_preview\EventSubscriber;

use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps experimental responses out of shared page and CDN caches.
 */
final class PreviewResponseSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the preview response policy.
   */
  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly KillSwitch $killSwitch,
  ) {}

  /**
   * Marks previews as private after Drupal has finished building the response.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || $this->themeManager->getActiveTheme()->getName() !== 'labdoo_next') {
      return;
    }
    $this->killSwitch->trigger();
    $response = $event->getResponse();
    if ($response instanceof CacheableResponseInterface) {
      $response->getCacheableMetadata()->setCacheMaxAge(0);
    }
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    $response->headers->set('X-Labdoo-Theme', 'labdoo_next');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => [
        ['onResponse', 100],
        ['onResponse', -1000],
      ],
    ];
  }

}
