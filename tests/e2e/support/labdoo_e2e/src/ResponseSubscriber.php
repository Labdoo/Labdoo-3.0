<?php

namespace Drupal\labdoo_e2e;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks responses with the isolated checkout identity.
 */
final class ResponseSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber with the active connection and checkout root.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['respond', -1000]];
  }

  /**
   * Adds isolated-checkout identity headers to the response.
   */
  public function respond(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $database = $this->database->getConnectionOptions()['database'];
    if (!Settings::get('labdoo_e2e') || !str_starts_with($database, 'labdoo_e2e_')) {
      throw new \RuntimeException('E2E isolation failed');
    }
    $event->getResponse()->headers->set('X-Labdoo-E2E-Root', hash('sha256', dirname($this->appRoot)));
    $event->getResponse()->headers->set('X-Labdoo-E2E', Settings::get('labdoo_e2e_variant'));
  }

}
