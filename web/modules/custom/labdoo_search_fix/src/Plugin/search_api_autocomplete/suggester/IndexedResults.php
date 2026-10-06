<?php

namespace Drupal\labdoo_search_fix\Plugin\search_api_autocomplete\suggester;

use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api_autocomplete\Plugin\search_api_autocomplete\suggester\LiveResults;

/**
 * Provides live results with a small cap to keep autocomplete requests light.
 *
 * @SearchApiAutocompleteSuggester(
 *   id = "indexed_results",
 *   label = @Translation("Display live results (lightweight)"),
 *   description = @Translation("Display a small number of live results per request."),
 * )
 */
class IndexedResults extends LiveResults {

  /**
   * {@inheritdoc}
   */
  public function getAutocompleteSuggestions(QueryInterface $query, $incomplete_key, $user_input) {
    // The standard suggester loads every matching entity to build its output.
    // Limit that work independently of the global autocomplete limit.
    $query->range(0, 5);
    $query->setOption('skip result count', TRUE);
    return parent::getAutocompleteSuggestions($query, $incomplete_key, $user_input);
  }

}
