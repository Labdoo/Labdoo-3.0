<?php

namespace Drupal\labdoo_common\Service\Repository;

use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides methods to retrieve view data.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ViewRepository {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected Request $request;

  /**
   * ViewRepository constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   */
  public function __construct(RequestStack $requestStack) {
    $this->request = $requestStack->getCurrentRequest();
  }

  /**
   * Retrieves the results of the given view.
   *
   * @param string $viewId
   *   The view ID.
   * @param string $displayId
   *   The display ID.
   * @param int|null $itemsPerPage
   *   If set, defines the items per page.
   * @param int $page
   *   The page number.
   *
   * @return \Drupal\views\ResultRow[]
   *   The view results.
   */
  public function getResults(
    string $viewId,
    string $displayId,
    ?int $itemsPerPage = NULL,
    int $page = 0
  ): array {
    $view = Views::getView($viewId);
    if (!$view) {
      throw new NotFoundHttpException();
    }

    $view->setDisplay($displayId);
    $viewArgs = $this->request->query->all();
    $filters = [];

    foreach ($viewArgs as $name => $value) {
      $filters[$name] = $value;
    }

    $view->setExposedInput($filters);

    if ($itemsPerPage !== NULL) {
      $view->setItemsPerPage($itemsPerPage);
      $view->setCurrentPage($page);
    }

    $view->execute();

    return $view->result;
  }

}
