<?php

namespace Drupal\mini_wiki\Plugin\pathauto\AliasType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\pathauto\Plugin\pathauto\AliasType\EntityAliasTypeBase;

/**
 * Provides a Pathauto alias type for this custom entity.
 *
 * @AliasType(
 *   id = "mini_wiki_page",
 *   label = @Translation("Mini Wiki Page"),
 *   entity_type = "mini_wiki_page"
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MiniWikiAliasType extends EntityAliasTypeBase {

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string|TranslatableMarkup {
    return $this->t('Mini Wiki Page');
  }

}
