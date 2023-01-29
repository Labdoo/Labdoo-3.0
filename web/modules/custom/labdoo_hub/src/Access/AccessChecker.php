<?php

namespace Drupal\labdoo_hub\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks access for certain routes.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class AccessChecker implements AccessInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected CurrentRouteMatch $currentRouteMatch;

  /**
   * Class constructor.
   *
   * @param CurrentRouteMatch $currentRouteMatch
   *   The current route match.
   */
  public function __construct(
    CurrentRouteMatch $currentRouteMatch
  ) {

    $this->currentRouteMatch = $currentRouteMatch;
  }

  /**
   * A custom access check.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   *
   * @return \Drupal\Core\Access\AccessResultAllowed|\Drupal\Core\Access\AccessResultForbidden|\Drupal\Core\Access\AccessResultNeutral
   */
  public function access(AccountInterface $account) {
    $allowedRoles = [
      'freelancer',
      'colaborador',
      'organizacion'
    ];
    if (
      $this->currentRouteMatch->getRouteName() === 'worklog_widgets.billing_history'
      && !array_intersect($allowedRoles, $account->getRoles())
    ) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowed();
  }

}
