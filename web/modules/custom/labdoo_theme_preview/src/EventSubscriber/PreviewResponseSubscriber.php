<?php

namespace Drupal\labdoo_theme_preview\EventSubscriber;

use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\labdoo_theme_preview\Theme\PreviewNegotiator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
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
    private readonly PreviewNegotiator $previewNegotiator,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Loads preview hooks before Dynamic Page Cache can bypass the controller.
   */
  public function initializePreviewTheme(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$this->previewNegotiator->applies($this->routeMatch)) {
      return;
    }
    // Use normal negotiation: its access check also registers theme namespaces.
    $this->themeManager->resetActiveTheme();
    $this->themeManager->getActiveTheme();
  }

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
      // Routing runs at 32; Dynamic Page Cache checks for a hit at 27.
      KernelEvents::REQUEST => ['initializePreviewTheme', 28],
      KernelEvents::RESPONSE => [
        ['onResponse', 100],
        ['preventSharedStorage', -1000],
      ],
    ];
  }

}
