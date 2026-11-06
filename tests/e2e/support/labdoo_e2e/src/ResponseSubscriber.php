<?php

namespace Drupal\labdoo_e2e;

use Drupal\Core\Site\Settings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ResponseSubscriber implements EventSubscriberInterface {
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['respond', -1000]];
  }

  public function respond(ResponseEvent $event): void {
    if (!$event->isMainRequest()) { return; }
    $database = \Drupal::database()->getConnectionOptions()['database'];
    if (!Settings::get('labdoo_e2e') || !str_starts_with($database, 'labdoo_e2e_')) {
      throw new \RuntimeException('E2E isolation failed');
    }
    $event->getResponse()->headers->set('X-Labdoo-E2E', Settings::get('labdoo_e2e_variant'));
  }
}
