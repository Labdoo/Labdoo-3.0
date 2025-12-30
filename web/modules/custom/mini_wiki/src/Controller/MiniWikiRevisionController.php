<?php

namespace Drupal\mini_wiki\Controller;

use Drupal\diff\Controller\PluginRevisionController;
use Drupal\mini_wiki\MiniWikiPageInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Returns responses for Mini Wiki Page Revision routes.
 */
class MiniWikiRevisionController extends PluginRevisionController {

  /**
   * Returns a form for revision overview page.
   *
   * @param \Drupal\mini_wiki\MiniWikiPageInterface $mini_wiki_page
   *   The wiki page whose revisions are inspected.
   *
   * @return array
   *   Render array containing the revisions table.
   */
  public function revisionOverview(MiniWikiPageInterface $mini_wiki_page) {
    if (!$mini_wiki_page->access('view')) {
      throw new AccessDeniedHttpException();
    }
    return $this->formBuilder()->getForm('Drupal\mini_wiki\Form\MiniWikiRevisionOverviewForm', $mini_wiki_page);
  }

}
