<?php

namespace Drupal\mini_wiki\Form;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\diff\Form\RevisionOverviewForm;

/**
 * Provides a revision overview form for mini wiki pages.
 */
class MiniWikiRevisionOverviewForm extends RevisionOverviewForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $mini_wiki_page = NULL) {
    /** @var \Drupal\mini_wiki\Entity\MiniWikiPage $mini_wiki_page */
    $account = $this->currentUser;
    $langcode = $mini_wiki_page->language()->getId();
    $langname = $mini_wiki_page->language()->getName();
    $languages = $mini_wiki_page->getTranslationLanguages();
    $has_translations = (count($languages) > 1);
    $entity_type_id = $mini_wiki_page->getEntityTypeId();
    $storage = $this->entityTypeManager->getStorage($entity_type_id);

    $pagerLimit = $this->config->get('general_settings.revision_pager_limit');

    $query = $storage->getQuery()
      ->condition($mini_wiki_page->getEntityType()->getKey('id'), $mini_wiki_page->id())
      ->pager($pagerLimit)
      ->allRevisions()
      ->sort($mini_wiki_page->getEntityType()->getKey('revision'), 'DESC')
      ->accessCheck(FALSE)
      ->execute();
    $vids = array_keys($query);

    $revision_count = count($vids);

    $build['#title'] = $has_translations ? $this->t('@langname revisions for %title', [
      '@langname' => $langname,
      '%title' => $mini_wiki_page->label(),
    ]) : $this->t('Revisions for %title', [
      '%title' => $mini_wiki_page->label(),
    ]);
    $build['mini_wiki_page_id'] = [
      '#type' => 'hidden',
      '#value' => $mini_wiki_page->id(),
    ];

    $table_header = [];
    $table_header['revision'] = $this->t('Revision information');

    if ($revision_count > 1) {
      $table_caption = $this->t('Use the radio buttons in the table below to select two revisions to compare. Then click the "Compare selected revisions" button to generate the comparison.');
      $table_header += [
        'select_column_one' => $this->t('Source revision'),
        'select_column_two' => $this->t('Target revision'),
      ];
    }
    $table_header['operations'] = $this->t('Operations');

    // MiniWikiPage doesn't have bundles/types, so we check general permissions.
    $revert_permission = $account->hasPermission('administer mini_wiki_page') && $mini_wiki_page->access('update');
    $delete_permission = $account->hasPermission('administer mini_wiki_page') && $mini_wiki_page->access('delete');

    $compare_revision_submit = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Compare selected revisions'),
      '#attributes' => [
        'class' => [
          'diff-button',
        ],
      ],
    ];

    if ($revision_count > 5) {
      $build['submit_top'] = $compare_revision_submit;
    }

    $build['revisions_table'] = [
      '#type' => 'table',
      '#caption' => $table_caption ?? '',
      '#header' => $table_header,
      '#attributes' => ['class' => ['diff-revisions']],
    ];

    $build['revisions_table']['#attached']['library'][] = 'diff/diff.general';
    $build['revisions_table']['#attached']['drupalSettings']['diffRevisionRadios'] = $this->config->get('general_settings.radio_behavior');

    $default_revision = $mini_wiki_page->getRevisionId();
    foreach ($vids as $key => $vid) {
      $previous_revision = NULL;
      if (isset($vids[$key + 1])) {
        $previous_revision = $storage->loadRevision($vids[$key + 1]);
      }
      /** @var \Drupal\Core\Entity\ContentEntityInterface $revision */
      if ($revision = $storage->loadRevision($vid)) {
        if ($revision->hasTranslation($langcode) && $revision->getTranslation($langcode)->isRevisionTranslationAffected()) {
          $username = [
            '#theme' => 'username',
            '#account' => $revision->getRevisionUser(),
          ];
          $revision_date = $this->date->format($revision->getRevisionCreationTime(), 'short');
          if ($vid != $mini_wiki_page->getRevisionId()) {
            $link = Link::fromTextAndUrl($revision_date, new Url('entity.mini_wiki_page.revision', [
              'mini_wiki_page' => $mini_wiki_page->id(),
              'mini_wiki_page_revision' => $vid,
            ]));
          }
          else {
            $link = $mini_wiki_page->toLink($revision_date);
          }

          if ($vid == $default_revision) {
            $row = [
              'revision' => $this->buildRevision($link, $username, $revision, $previous_revision),
            ];

            if ($revision_count > 1) {
              $row['select_column_one'] = $this->buildSelectColumn('radios_left', $vid, FALSE);
              $row['select_column_two'] = $this->buildSelectColumn('radios_right', $vid, $vid);
            }

            $row['operations'] = [
              '#prefix' => '<em>',
              '#markup' => $this->t('Current revision'),
              '#suffix' => '</em>',
            ];

            $row['#attributes'] = [
              'class' => ['revision-current'],
            ];
          }
          else {
            $row = [
              'revision' => $this->buildRevision($link, $username, $revision, $previous_revision),
            ];

            if ($revision_count > 1) {
              $row['select_column_one'] = $this->buildSelectColumn('radios_left', $vid, $vid);
              $row['select_column_two'] = $this->buildSelectColumn('radios_right', $vid, FALSE);
            }

            $links = [];
            if ($revert_permission) {
              $links['revert'] = [
                'title' => $this->t('Revert'),
                'url' => Url::fromRoute('entity.mini_wiki_page.revision_revert_form', [
                  'mini_wiki_page' => $mini_wiki_page->id(),
                  'mini_wiki_page_revision' => $vid,
                ]),
              ];
            }
            if ($delete_permission) {
              $links['delete'] = [
                'title' => $this->t('Delete'),
                'url' => Url::fromRoute('entity.mini_wiki_page.revision_delete_form', [
                  'mini_wiki_page' => $mini_wiki_page->id(),
                  'mini_wiki_page_revision' => $vid,
                ]),
              ];
            }

            $row['operations'] = [
              '#type' => 'operations',
              '#links' => $links,
            ];
          }

          $build['revisions_table'][] = $row;
        }
      }
    }

    if ($revision_count > 1) {
      $build['submit'] = $compare_revision_submit;
    }
    $build['pager'] = [
      '#type' => 'pager',
    ];
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $input = $form_state->getUserInput();

    $revisions = $form_state->getValue('revisions_table');
    if (!is_countable($revisions) || count($revisions) <= 1) {
      $form_state->setErrorByName('revisions_table', $this->t('Multiple revisions are needed for comparison.'));
    }
    elseif (!isset($input['radios_left']) || !isset($input['radios_right'])) {
      $form_state->setErrorByName('revisions_table', $this->t('Select two revisions to compare.'));
    }
    elseif ($input['radios_left'] == $input['radios_right']) {
      $form_state->setErrorByName('revisions_table', $this->t('Select different revisions to compare.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $input = $form_state->getUserInput();
    $vid_left = $input['radios_left'];
    $vid_right = $input['radios_right'];
    $mini_wiki_page_id = $input['mini_wiki_page_id'];

    if ($vid_left > $vid_right) {
      $aux = $vid_left;
      $vid_left = $vid_right;
      $vid_right = $aux;
    }

    $redirect_url = Url::fromRoute(
      'entity.mini_wiki_page.revisions_diff',
      [
        'mini_wiki_page' => $mini_wiki_page_id,
        'left_revision' => $vid_left,
        'right_revision' => $vid_right,
        'filter' => $this->diffLayoutManager->getDefaultLayout(),
      ],
    );
    $form_state->setRedirectUrl($redirect_url);
  }

}
