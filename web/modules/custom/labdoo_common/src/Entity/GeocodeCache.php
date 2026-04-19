<?php

namespace Drupal\labdoo_common\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the geocode cache entity.
 *
 * @ContentEntityType(
 *   id = "labdoo_geocode_cache",
 *   label = @Translation("Labdoo geocode cache"),
 *   base_table = "labdoo_geocode_cache",
 *   entity_keys = {
 *     "id" = "id"
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage"
 *   },
 *   admin_permission = "administer site configuration"
 * )
 */
class GeocodeCache extends ContentEntityBase {
  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['address_normalized'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Normalized address'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['address_original'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Original address'))
      ->setRequired(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['latitude'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Latitude'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['longitude'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Longitude'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Provider'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['hits'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Hits'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
