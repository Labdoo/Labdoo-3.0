<?php

namespace Drupal\labdoo_team\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\labdoo_team\Service\MembershipManager;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field handler to check the membership of a user to a team.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("team_membership_field")
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class TeamMembershipField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected MembershipManager $teamMembershipManager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('labdoo_team.membership.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Leave empty to avoid a query on this field.
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['team_id_field_name'] = ['default' => ''];

    return $options;
  }

  /**
   * Define the form for the options.
   * @see \Drupal\views\Plugin\views\field\FieldPluginBase::buildOptionsForm()
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $fieldOptions = $this->displayHandler->getFieldLabels(TRUE);

    $form['team_id_field_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Team ID field'),
      '#default_value' => $this->options['team_id_field_name'],
      '#options' => $fieldOptions,
      '#description' => $this->t('Select the field that represents the Team ID.'),
    ];

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $teamIdFieldName = $this->options['team_id_field_name'];
    $teamId = $values->{$teamIdFieldName};

    if (!$this->teamMembershipManager->checkUserMembership($teamId)) {
      return $this->teamMembershipManager
        ->buildJoinLink($teamId)
        ->toRenderable();
    }

    return $this->teamMembershipManager
      ->buildLeaveLink($teamId)
      ->toRenderable();
  }

}
