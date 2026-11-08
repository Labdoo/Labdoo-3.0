<?php

namespace Drupal\labdoo_theme_preview\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Selects only the explicitly allowed, installed preview theme.
 */
final class PreviewNegotiator implements ThemeNegotiatorInterface {

  /**
   * Constructs the explicit theme selector.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $request = $this->requestStack->getCurrentRequest();
    $theme = $request?->query->all()['theme'] ?? NULL;
    if ($theme !== 'labdoo_next' || $route_match->getRouteObject()?->getOption('_admin_route')) {
      return FALSE;
    }
    // Keep downloads and print documents in their existing clean layouts.
    $route = $route_match->getRouteName() ?? '';
    if (str_contains($route, 'print') || str_contains($route, 'export')) {
      return FALSE;
    }
    return array_key_exists('labdoo_next', $this->configFactory->get('core.extension')->get('theme') ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): string {
    return 'labdoo_next';
  }

}
