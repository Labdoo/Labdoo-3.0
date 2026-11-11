<?php

namespace Drupal\labdoo_quotes;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;

/**
 * Provides sourced quotes translated through Drupal's interface translator.
 */
final class QuoteCatalog {

  public const CONTEXT = 'Labdoo education quotes';

  /**
   * Constructs the quote catalog.
   */
  public function __construct(
    private readonly ModuleExtensionList $modules,
    private readonly TranslationInterface $translation,
    private readonly LanguageManagerInterface $languages,
  ) {}

  /**
   * Returns all quotes in the requested interface language.
   */
  public function all(?string $langcode = NULL): array {
    $langcode ??= $this->languages->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    $path = DRUPAL_ROOT . '/' . $this->modules->getPath('labdoo_quotes') . '/quotes.json';
    $quotes = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    $options = ['context' => self::CONTEXT, 'langcode' => $langcode];
    foreach ($quotes as &$quote) {
      // These sources are maintained in the module and its bundled PO files.
      $quote['text'] = (string) $this->translation->translate($quote['text'], [], $options);
      $quote['author'] = (string) $this->translation->translate($quote['author'], [], $options);
    }
    return $quotes;
  }

  /**
   * Selects a random quote, excluding the previous quote when it is known.
   */
  public function random(string $langcode, ?string $previous = NULL): array {
    $quotes = $this->all($langcode);
    $choices = array_values(array_filter($quotes, static fn(array $quote) => $quote['id'] !== $previous));
    $choices = $choices ?: $quotes;
    return $choices[random_int(0, count($choices) - 1)];
  }

  /**
   * Builds a cacheable translated fallback and a no-cache AJAX endpoint URL.
   */
  public function build(array $attributes = []): array {
    $language = $this->languages->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE);
    $quotes = $this->all($language->getId());
    $attributes += [
      'lang' => $language->getId(),
      'dir' => $language->getDirection(),
      'data-quote-url' => Url::fromRoute('labdoo_quotes.random', ['langcode' => $language->getId()], ['language' => $language])->toString(),
    ];
    return [
      '#theme' => 'labdoo_education_quote',
      '#quote' => $quotes[0],
      '#attributes' => $attributes,
      '#langcode' => $language->getId(),
      '#attached' => [
        'library' => ['labdoo_quotes/quotes'],
      ],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['locale', 'config:core.extension'],
      ],
    ];
  }

}
