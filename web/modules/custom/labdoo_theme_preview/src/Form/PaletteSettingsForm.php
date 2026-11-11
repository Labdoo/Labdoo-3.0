<?php

namespace Drupal\labdoo_theme_preview\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\labdoo_theme_preview\PaletteManager;

/**
 * Creates named palettes and selects the site's active theme colors.
 */
final class PaletteSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'labdoo_next_palette_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['labdoo_theme_preview.palettes'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'labdoo_theme_preview/palette-editor';
    $manager = new PaletteManager($this->configFactory());
    $palettes = $manager->all();
    $active = $manager->active();
    $requested = $this->getRequest()->query->all()['edit'] ?? NULL;
    if (is_string($requested) && isset($palettes[$requested])) {
      $active = ['id' => $requested] + $palettes[$requested];
    }
    $form['#attached']['drupalSettings']['labdooNextPalettes'] = $palettes;
    $form['active'] = [
      '#type' => 'select',
      '#title' => $this->t('Active palette'),
      '#options' => array_map(static fn(array $palette) => $palette['label'], $palettes),
      '#default_value' => $active['id'],
      '#description' => $this->t('Select a palette to load its name and colors. Saving applies it to all visitors using Labdoo Next.'),
    ];
    $form['load'] = [
      '#type' => 'submit',
      '#name' => 'load_palette',
      '#value' => $this->t('Load palette'),
      '#submit' => ['::loadPalette'],
      '#limit_validation_errors' => [['active']],
      '#attributes' => ['class' => ['ldn-palette-load']],
    ];
    $form['loaded_palette'] = [
      '#type' => 'hidden',
      '#default_value' => $active['id'],
    ];
    $form['create'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create a new palette'),
    ];
    $form['new_palette'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Palette colors'),
      '#tree' => TRUE,
    ];
    $form['new_palette']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Palette name'),
      '#maxlength' => 80,
      '#default_value' => $active['label'],
      '#required' => TRUE,
      '#description' => $this->t('Edit this palette, or choose Create a new palette to save a separate copy.'),
    ];
    $labels = [
      'green' => $this->t('Primary color (links and buttons)'),
      'orange' => $this->t('Accent color'),
      'ink' => $this->t('Text'),
      'muted' => $this->t('Secondary text'),
      'canvas' => $this->t('Page background'),
      'paper' => $this->t('Card background'),
      'soft' => $this->t('Soft background (tables and metrics)'),
      'preview' => $this->t('Preview bar background'),
      'line' => $this->t('Borders'),
      'control-border' => $this->t('Form control borders'),
      'primary-hover' => $this->t('Button hover color'),
    ];
    $form['new_palette']['colors'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ldn-palette-colors']],
    ];
    foreach ($labels as $key => $label) {
      $form['new_palette']['colors'][$key] = [
        '#type' => 'color',
        '#title' => $label,
        '#default_value' => $active['colors'][$key],
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'load_palette') {
      return;
    }
    if ($form_state->getValue('active') !== $form_state->getValue('loaded_palette')) {
      $form_state->setErrorByName('active', $this->t('Load the selected palette before saving.'));
    }
    $label = trim((string) $form_state->getValue(['new_palette', 'label']));
    if ($label === '') {
      $form_state->setErrorByName('new_palette][label', $this->t('Enter a palette name.'));
    }
    $manager = new PaletteManager($this->configFactory());
    foreach ($manager->all() as $id => $palette) {
      if (!$form_state->getValue('create') && $id === $form_state->getValue('active')) {
        continue;
      }
      if (mb_strtolower($palette['label']) === mb_strtolower($label)) {
        $form_state->setErrorByName('new_palette][label', $this->t('A palette with this name already exists.'));
        break;
      }
    }
    foreach ($form_state->getValue(['new_palette', 'colors']) ?? [] as $key => $value) {
      if (!is_string($value) || !preg_match('/^#[a-f0-9]{6}$/iD', $value)) {
        $form_state->setErrorByName('new_palette][colors][' . $key, $this->t('Use a hexadecimal color such as #245c40.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('labdoo_theme_preview.palettes');
    $active = $form_state->getValue('active');
    if ($form_state->getValue('create')) {
      $active = 'custom_' . bin2hex(random_bytes(6));
    }
    $custom = $config->get('custom') ?? [];
    $custom[$active] = [
      'label' => trim($form_state->getValue(['new_palette', 'label'])),
      'colors' => array_map('strtolower', $form_state->getValue(['new_palette', 'colors'])),
    ];
    $config->set('custom', $custom);
    $config->set('active', $active)->save();
    parent::submitForm($form, $form_state);
    $form_state->setRedirect('labdoo_theme_preview.palettes');
  }

  /**
   * Loads the selected palette when JavaScript is unavailable.
   */
  public function loadPalette(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('labdoo_theme_preview.palettes', [], [
      'query' => ['edit' => $form_state->getValue('active')],
    ]);
  }

}
