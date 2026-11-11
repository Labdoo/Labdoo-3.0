<?php

namespace Drupal\labdoo_theme_preview\EventSubscriber;

use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
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
    $response = $event->getResponse();
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    $response->headers->set('X-Labdoo-Theme', 'labdoo_next');
  }

  /**
   * Keeps previews out of page/CDN caches after contextual DPC storage.
   */
  public function preventSharedStorage(ResponseEvent $event): void {
    $this->onResponse($event);
    if ($event->isMainRequest() && $this->themeManager->getActiveTheme()->getName() === 'labdoo_next') {
      // Dynamic Page Cache has already stored the cacheable shell with Drupal's
      // contexts and placeholders. Deny the anonymous full-page cache, while
      // preserving private/no-store for browsers and external shared caches.
      $this->killSwitch->trigger();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => [
        ['onResponse', 100],
        ['preventSharedStorage', -1000],
      ],
    ];
  }

}
