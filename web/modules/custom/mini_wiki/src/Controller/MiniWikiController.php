<?php

namespace Drupal\mini_wiki\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
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
   * @param LanguageManagerInterface $language_manager The language manager interface
   */
  public function __construct(
    protected ConfigFactoryInterface $config_factory,
    protected LanguageManagerInterface $language_manager
  ) {
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('language_manager')
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
    $wikiRootPage = NULL;
    $currentLanguage = $this->language_manager->getCurrentLanguage()->getId();

    if (!empty($entityId)) {
      // Load the wiki root page.
      $wikiRootPage = MiniWikiPage::load($entityId);
      if ($wikiRootPage && $wikiRootPage->hasTranslation($currentLanguage)) {
        $wikiRootPage = $wikiRootPage->getTranslation($currentLanguage);
      }
    }

    if (empty($wikiRootPage)) {
      $storage = $this->entityTypeManager()->getStorage('mini_wiki_page');
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('parent', NULL, 'IS NULL')
        ->condition('langcode', $currentLanguage)
        ->sort('id', 'ASC')
        ->range(0, 1);

      $ids = $query->execute();

      if (!empty($ids)) {
        $wikiRootPage = $storage->load(reset($ids));
      }
    }

    if (empty($wikiRootPage)) {
      throw new NotFoundHttpException('The wiki root page has not been set and no default could be found');
    }

    $url = $wikiRootPage->toUrl()->toString();

    return new RedirectResponse($url);
  }

}
