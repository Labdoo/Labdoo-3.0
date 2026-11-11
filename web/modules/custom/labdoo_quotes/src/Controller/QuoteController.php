<?php

namespace Drupal\labdoo_quotes\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\labdoo_quotes\QuoteCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns one translated education quote outside the page cache.
 */
final class QuoteController implements ContainerInjectionInterface {

  /**
   * Constructs the endpoint.
   */
  public function __construct(
    private readonly QuoteCatalog $catalog,
    private readonly LanguageManagerInterface $languages,
    private readonly KillSwitch $killSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('labdoo_quotes.catalog'), $container->get('language_manager'), $container->get('page_cache_kill_switch'));
  }

  /**
   * Returns a quote in an installed interface language without immediate repeats.
   */
  public function random(string $langcode, Request $request): JsonResponse {
    $this->killSwitch->trigger();
    if (!isset($this->languages->getLanguages()[$langcode])) {
      throw new NotFoundHttpException();
    }
    $previous = $request->query->all()['exclude'] ?? NULL;
    $quote = $this->catalog->random($langcode, is_string($previous) ? $previous : NULL);
    return new JsonResponse(['language' => $langcode, 'quote' => $quote], 200, [
      'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
      'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
