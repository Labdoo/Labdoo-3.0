<?php

namespace Drupal\mini_wiki\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\mini_wiki\Entity\MiniWikiPage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the mini wiki.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MiniWikiController extends ControllerBase {

  /**
   * Constructs a new instance of the class.
   *
   * @param ConfigFactoryInterface $config_factory The configuration factory interface
   */
  public function __construct(protected ConfigFactoryInterface $config_factory) {
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * Redirects to the root page of the mini wiki.
   *
   * @return RedirectResponse
   *   The redirect response to the root page
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   *   When the wiki root page has not been set or is not valid
   */
  public function redirectToRoot(): RedirectResponse {
    $config = $this->config_factory->get('mini_wiki_page.settings');
    $entityId = $config->get('root_page');
    if (empty($entityId)) {
      throw new NotFoundHttpException('The wiki root page has not been set');
    }

    // Load the wiki root page.
    $wikiRootPage = MiniWikiPage::load($entityId);
    if (empty($wikiRootPage)) {
      throw new NotFoundHttpException('The wiki root page set is not valid');
    }

    $url = $wikiRootPage->toUrl()->toString();

    return new RedirectResponse($url);
  }

}
