<?php

/**
 * @file
 * This module implements the Labdoo blocks.
 */

namespace Drupal\lbd_blocks\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a block with a simple text.
 *
 * @Block(
 *   id = "lbd_block_hub_edoovillage",
 *   admin_label = @Translation("Block: Hub & Edoovillage"),
 * )
 */
class BlockHubEdoovillage extends BlockBase
{
  /**
   * {@inheritdoc}
   */
  public function build()
  {
    return [
      '#markup' => $this->t('This is a simple block!'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account)
  {
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state)
  {
    $config = $this->getConfiguration();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state)
  {
    $this->configuration['my_block_settings'] = $form_state->getValue('my_block_settings');
  }
}