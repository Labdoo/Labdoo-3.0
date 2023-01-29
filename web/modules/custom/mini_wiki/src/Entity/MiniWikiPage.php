<?php

declare(strict_types=1);

namespace Drupal\mini_wiki\Entity;

use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\mini_wiki\MiniWikiPageInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the wiki page entity class.
 *
 * @ContentEntityType(
 *   id = "mini_wiki_page",
 *   label = @Translation("Wiki Page"),
 *   label_collection = @Translation("Wiki Pages"),
 *   label_singular = @Translation("wiki page"),
 *   label_plural = @Translation("wiki pages"),
 *   label_count = @PluralTranslation(
 *     singular = "@count wiki pages",
 *     plural = "@count wiki pages",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\mini_wiki\MiniWikiPageListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\mini_wiki\MiniWikiPageAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\mini_wiki\Form\MiniWikiPageForm",
 *       "edit" = "Drupal\mini_wiki\Form\MiniWikiPageForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *       "delete-multiple-confirm" = "Drupal\Core\Entity\Form\DeleteMultipleForm",
 *       "revision-delete" = \Drupal\Core\Entity\Form\RevisionDeleteForm::class,
 *       "revision-revert" = \Drupal\Core\Entity\Form\RevisionRevertForm::class,
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *       "revision" = \Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider::class,
 *     },
 *   },
 *   base_table = "mini_wiki_page",
 *   data_table = "mini_wiki_page_field_data",
 *   revision_table = "mini_wiki_page_revision",
 *   revision_data_table = "mini_wiki_page_field_revision",
 *   show_revision_ui = TRUE,
 *   translatable = TRUE,
 *   admin_permission = "administer mini_wiki_page",
 *   fieldable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "revision_id",
 *     "langcode" = "langcode",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_uid",
 *     "revision_created" = "revision_timestamp",
 *     "revision_log_message" = "revision_log",
 *   },
 *   links = {
 *     "collection" = "/admin/content/wiki-page",
 *     "add-form" = "/wiki-page/add",
 *     "canonical" = "/wiki-page/{mini_wiki_page}",
 *     "edit-form" = "/wiki-page/{mini_wiki_page}/edit",
 *     "delete-form" = "/wiki-page/{mini_wiki_page}/delete",
 *     "delete-multiple-form" = "/admin/content/page/delete-multiple",
 *     "revision" = "/wiki-page/{mini_wiki_page}/revision/{mini_wiki_page_revision}/view",
 *     "revision-delete-form" = "/wiki-page/{mini_wiki_page}/revision/{mini_wiki_page_revision}/delete",
 *     "revision-revert-form" = "/wiki-page/{mini_wiki_page}/revision/{mini_wiki_page_revision}/revert",
 *     "version-history" = "/wiki-page/{mini_wiki_page}/revisions",
 *   },
 *   field_ui_base_route = "entity.mini_wiki_page.settings",
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
final class MiniWikiPage extends RevisionableContentEntityBase implements MiniWikiPageInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if (!$this->getOwnerId()) {
      // If no owner has been set explicitly, make the anonymous user the owner.
      $this->setOwnerId(0);
    }

    $this->setRevisionUserId($this->getOwnerId());

    // Clear the cache tags.
    $cacheTags = [];

    if (!empty($this->id())) {
      $cacheTags[] = 'entity:' . $this->id();
    }

    $parentEntity = $this->get('parent')->entity;
    if ($parentEntity !== NULL) {
      $cacheTags[] = 'wiki:' . $parentEntity->id();
    }

    if (!empty($cacheTags)) {
      \Drupal::service('cache_tags.invalidator')->invalidateTags($cacheTags);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(t('Label'))
      ->setDefaultValue(TRUE)
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setRevisionable(TRUE)
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setTranslatable(TRUE)
      ->setDescription(t('The time that the wiki page was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setTranslatable(TRUE)
      ->setDescription(t('The time that the wiki page was last edited.'));

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language'))
      ->setDisplayOptions('view', [
        'region' => 'hidden',
      ])
      ->setDisplayOptions('form', [
        'type' => 'language_select',
        'weight' => 2,
      ])
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setTranslatable(TRUE)
      ->setRequired(TRUE);

    $fields['body'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Body'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'text_textfield',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'text_textfield',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setSetting(
        'allowed_values_function',
        'mini_wiki_allowed_text_formats'
      );

    $fields['parent'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(t('Parent page'))
      ->setDescription(t('Leave empty if this is a root page'))
      ->setSetting('target_type', 'mini_wiki_page')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'entity_reference_label',
        'weight' => 11,
      ))
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 11,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['path'] = BaseFieldDefinition::create('path')
      ->setLabel(t('URL alias'))
      ->setDisplayOptions('form', [
        'type' => 'pathauto',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

}
