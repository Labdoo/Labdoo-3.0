<?php

namespace Drupal\simple_slideshow\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'simple_slideshow' formatter.
 *
 * @FieldFormatter(
 *   id = "simple_slideshow",
 *   label = @Translation("Simple Slideshow"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SimpleSlideshow extends EntityReferenceFormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Constructs a SimpleSlideshow object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct($plugin_id, $plugin_definition, $field_definition, array $settings, $label, $view_mode, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager, FileUrlGeneratorInterface $file_url_generator) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'image_style' => 'large',
      'autoplay' => TRUE,
      'autoplay_speed' => 3000,
      'dots' => TRUE,
      'arrows' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $form = parent::settingsForm($form, $form_state);

    $image_styles = image_style_options(FALSE);
    $form['image_style'] = [
      '#title' => $this->t('Image style'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('image_style'),
      '#empty_option' => $this->t('None (original image)'),
      '#options' => $image_styles,
      '#description' => $this->t('Select the image style to use for the slideshow.'),
    ];

    $form['autoplay'] = [
      '#title' => $this->t('Autoplay'),
      '#type' => 'checkbox',
      '#default_value' => $this->getSetting('autoplay'),
      '#description' => $this->t('Enable automatic slideshow.'),
    ];

    $form['autoplay_speed'] = [
      '#title' => $this->t('Autoplay speed'),
      '#type' => 'number',
      '#default_value' => $this->getSetting('autoplay_speed'),
      '#description' => $this->t('Time in milliseconds between slides.'),
      '#min' => 1000,
      '#step' => 500,
      '#states' => [
        'visible' => [
          ':input[name$="[autoplay]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['dots'] = [
      '#title' => $this->t('Show dots'),
      '#type' => 'checkbox',
      '#default_value' => $this->getSetting('dots'),
      '#description' => $this->t('Show navigation dots.'),
    ];

    $form['arrows'] = [
      '#title' => $this->t('Show arrows'),
      '#type' => 'checkbox',
      '#default_value' => $this->getSetting('arrows'),
      '#description' => $this->t('Show navigation arrows.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [];
    $image_styles = image_style_options(FALSE);

    // Unset the 'original' option.
    unset($image_styles['']);

    // Styles could be lost because of enabled/disabled modules that defines
    // their styles in code.
    $image_style_setting = $this->getSetting('image_style');
    if (isset($image_styles[$image_style_setting])) {
      $summary[] = $this->t('Image style: @style', ['@style' => $image_styles[$image_style_setting]]);
    }
    else {
      $summary[] = $this->t('Original image');
    }

    $summary[] = $this->t('Autoplay: @autoplay', [
      '@autoplay' => $this->getSetting('autoplay') ? $this->t('Yes') : $this->t('No'),
    ]);

    if ($this->getSetting('autoplay')) {
      $summary[] = $this->t('Autoplay speed: @speed ms', [
        '@speed' => $this->getSetting('autoplay_speed'),
      ]);
    }

    $summary[] = $this->t('Show dots: @dots', [
      '@dots' => $this->getSetting('dots') ? $this->t('Yes') : $this->t('No'),
    ]);

    $summary[] = $this->t('Show arrows: @arrows', [
      '@arrows' => $this->getSetting('arrows') ? $this->t('Yes') : $this->t('No'),
    ]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    // Ensure that $items is an instance of EntityReferenceFieldItemListInterface
    if (!($items instanceof EntityReferenceFieldItemListInterface)) {
      return $elements;
    }

    $files = $this->getEntitiesToView($items, $langcode);

    // Early opt-out if the field is empty.
    if (empty($files)) {
      return $elements;
    }

    // Collect cache tags to be added for each item in the field.
    $cache_tags = [];

    // Get the image style setting.
    $image_style_setting = $this->getSetting('image_style');
    $image_style = NULL;
    if (!empty($image_style_setting)) {
      $image_style = $this->entityTypeManager->getStorage('image_style')->load($image_style_setting);
      if ($image_style) {
        $cache_tags = Cache::mergeTags($cache_tags, $image_style->getCacheTags());
      }
    }

    // Prepare the slideshow items.
    $items_for_slideshow = [];
    foreach ($files as $delta => $file) {
      $cache_tags = Cache::mergeTags($cache_tags, $file->getCacheTags());

      // Extract field item attributes for the theme function, and unset them
      // from the $item so that the field template does not re-render them.
      $item = $items[$delta];
      $item_attributes = $item->_attributes;
      unset($item->_attributes);

      // Handle both media entities and image files.
      if ($file->getEntityTypeId() === 'media') {
        // For media entities, we need to get the source field.
        $source_field = $file->getSource()->getConfiguration()['source_field'];
        if (!$file->hasField($source_field)) {
          continue;
        }

        $media_file = $file->get($source_field)->entity;
        if (!$media_file) {
          continue;
        }

        $uri = $media_file->getFileUri();
      }
      else {
        // Direct file entity.
        $uri = $file->getFileUri();
      }

      // Apply image style if set.
      $url = $this->fileUrlGenerator->generate($uri);
      if ($image_style) {
        $url = $image_style->buildUrl($uri);
      }

      // Build the item for the slideshow.
      $items_for_slideshow[] = [
        'url' => $url,
        'alt' => $file->hasField('field_media_image') && $file->get('field_media_image')->first() 
          ? $file->get('field_media_image')->first()->get('alt')->getString() 
          : '',
        'title' => $file->hasField('field_media_image') && $file->get('field_media_image')->first() 
          ? $file->get('field_media_image')->first()->get('title')->getString() 
          : '',
        'attributes' => $item_attributes,
      ];
    }

    // Build the slideshow.
    $elements[0] = [
      '#theme' => 'simple_slideshow',
      '#items' => $items_for_slideshow,
      '#settings' => [
        'autoplay' => $this->getSetting('autoplay'),
        'autoplay_speed' => $this->getSetting('autoplay_speed'),
        'dots' => $this->getSetting('dots'),
        'arrows' => $this->getSetting('arrows'),
      ],
      '#attached' => [
        'library' => ['simple_slideshow/slideshow'],
      ],
      '#cache' => [
        'tags' => $cache_tags,
      ],
    ];

    return $elements;
  }

}
