<?php

namespace Drupal\labdoo_common\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a custom breadcrumb builder for Labdoo.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class LabdooBreadcrumbBuilder implements BreadcrumbBuilderInterface {
  use StringTranslationTrait;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * Constructs a LabdooBreadcrumbBuilder object.
   */
  public function __construct(LanguageManagerInterface $language_manager, RouteMatchInterface $route_match) {
    $this->languageManager = $language_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL) {
    // Aplicar solo cuando la ruta contiene /content en el breadcrumb
    $path = \Drupal::service('path.current')->getPath();
    return strpos($path, '/content') !== FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $links = [];

    $breadcrumb->addCacheContexts(['url.path', 'languages:language_interface']);

    // Enlace a inicio
    $links[] = Link::createFromRoute($this->t('Home'), '<front>');

    // Obtener el idioma actual
    $current_language = $this->languageManager->getCurrentLanguage()->getId();

    // Si la ruta incluye /content, añadir un enlace con traducción apropiada
    $path = \Drupal::service('path.current')->getPath();
    if (preg_match('#^/([a-z]{2})/content#', $path, $matches)) {
      $langcode = $matches[1];

      // Solo mostrar "Content" si estamos en el idioma actual del sitio
      // o usar una traducción apropiada
      if ($langcode === $current_language) {
        $links[] = Link::createFromRoute($this->t('Content'), 'system.admin_content');
      }
    }

    return $breadcrumb->setLinks($links);
  }

}