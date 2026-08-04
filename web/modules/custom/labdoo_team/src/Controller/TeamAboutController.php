<?php

namespace Drupal\labdoo_team\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller to display the field_description of a team.
 */
class TeamAboutController extends ControllerBase {

  /**
   * Displays the field_description of the given team.
   *
   * @param int $teamId
   *   The team ID.
   *
   * @return array
   *   A render array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function about(int $teamId): array {
    $team = $this->entityTypeManager()->getStorage('node')->load($teamId);
    if (!$team || $team->bundle() !== 'team') {
      throw new NotFoundHttpException();
    }

    if ($team->hasField('field_description') && !$team->get('field_description')->isEmpty()) {
      return $team->get('field_description')->view('default');
    }

    return [
      '#markup' => $this->t('This team does not have a description yet.'),
    ];
  }

}
