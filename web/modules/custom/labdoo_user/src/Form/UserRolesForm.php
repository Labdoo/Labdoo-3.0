<?php

namespace Drupal\labdoo_user\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for editing user roles.
 */
class UserRolesForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'labdoo_user_roles_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, User $user = NULL) {
    if (!$user) {
      return [
        '#markup' => $this->t('User not found.'),
      ];
    }

    $form_state->set('user_entity', $user);

    $roles = Role::loadMultiple();
    $options = [];
    $current_user = \Drupal::currentUser();
    $is_admin = $current_user->hasPermission('administer permissions') || in_array('administrator', $current_user->getRoles());

    foreach ($roles as $role) {
      $role_id = $role->id();
      // Exclude anonymous and authenticated roles
      if (in_array($role_id, [Role::ANONYMOUS_ID, Role::AUTHENTICATED_ID])) {
        continue;
      }

      // Administrator role can only be managed by someone with 'administer permissions'
      if ($role_id === 'administrator') {
        if ($is_admin) {
          $options[$role_id] = $role->label();
        }
        continue;
      }

      // Check if user has specific permission to assign this role
      if ($is_admin || $current_user->hasPermission("assign $role_id role")) {
        $options[$role_id] = $role->label();
      }
    }

    $form['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#options' => $options,
      '#default_value' => $user->getRoles(),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save roles'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\user\Entity\User $user */
    $user = $form_state->get('user_entity');
    $submitted_roles = array_filter($form_state->getValue('roles'));
    
    $current_user = \Drupal::currentUser();
    $is_admin = $current_user->hasPermission('administer permissions') || in_array('administrator', $current_user->getRoles());

    // Roles that the user CAN manage (either they are in the form options or protected)
    $form_options = $form['roles']['#options'];
    $manageable_roles = array_keys($form_options);
    
    $current_roles = $user->getRoles();
    
    // 1. Remove roles that the user has permission to manage but were UNCHECKED
    foreach ($current_roles as $role_id) {
      if (in_array($role_id, $manageable_roles) && !isset($submitted_roles[$role_id])) {
        // Protect authenticated and administrator unless really intended and allowed
        if ($role_id !== Role::AUTHENTICATED_ID && ($role_id !== 'administrator' || $is_admin)) {
          $user->removeRole($role_id);
        }
      }
    }

    // 2. Add roles that the user has permission to manage and were CHECKED
    foreach ($submitted_roles as $role_id => $value) {
      if (in_array($role_id, $manageable_roles)) {
        $user->addRole($role_id);
      }
    }

    $user->save();

    $this->messenger()->addStatus($this->t('The roles for %user have been updated.', ['%user' => $user->getDisplayName()]));
  }

}
