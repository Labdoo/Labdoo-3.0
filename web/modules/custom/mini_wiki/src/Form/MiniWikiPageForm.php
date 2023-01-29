<?php

declare(strict_types=1);

namespace Drupal\mini_wiki\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mini_wiki\Entity\MiniWikiPage;

/**
 * Form controller for the wiki page entity edit forms.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
final class MiniWikiPageForm extends ContentEntityForm {


  /**
   * {@inheritDoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?MiniWikiPage $parent = NULL
  ) {
    $form = parent::buildForm($form, $form_state);

    if ($parent) {
      $form['parent']['widget'][0]['target_id']['#default_value'] = $parent;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $message_args = ['%label' => $this->entity->toLink()->toString()];
    $logger_args = [
      '%label' => $this->entity->label(),
      'link' => $this->entity->toLink($this->t('View'))->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New wiki page %label has been created.', $message_args));
        $this->logger('mini_wiki')->notice('New wiki page %label has been created.', $logger_args);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The wiki page %label has been updated.', $message_args));
        $this->logger('mini_wiki')->notice('The wiki page %label has been updated.', $logger_args);
        break;

      default:
        throw new \LogicException('Could not save the entity.');
    }

    $form_state->setRedirectUrl($this->entity->toUrl());

    return $result;
  }

}
