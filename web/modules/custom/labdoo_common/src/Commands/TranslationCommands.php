<?php

namespace Drupal\labdoo_common\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\labdoo_common\Service\LocalTranslationAgent;
use Drupal\labdoo_common\Service\TranslationWorkflow;
use Drupal\labdoo_translations\Catalog;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for maintaining version-controlled Labdoo translations.
 */
class TranslationCommands extends DrushCommands {

  /**
   * Constructs the translation commands.
   */
  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected LocalTranslationAgent $translationAgent = new LocalTranslationAgent(),
    protected ?Catalog $catalog = NULL,
    protected ?TranslationWorkflow $workflow = NULL,
  ) {
    parent::__construct();
  }

  /**
   * Exports untranslated strings for enabled languages into versioned PO files.
   *
   * Existing PO entries are retained so translations already committed are not
   * lost when new source strings are added.
   *
   * @command labdoo:translations-update
   * @aliases l-tu
   * @option langcodes
   *   Optional comma-separated language codes. Defaults to all configurable
   *   languages except the site's default language.
   * @option dry-run
   *   Report pending strings without writing PO files.
   * @option output-dir
   *   Output directory. Defaults to the repository's translations/custom
   *   folder.
   * @option agent
   *   Translate missing entries with a local CLI: claude, codex, gemini or
   *   custom.
   * @option agent-binary
   *   Optional executable path for the selected CLI.
   * @option agent-model
   *   Optional model name passed to the CLI.
   * @option batch-size
   *   Maximum strings per agent request (1–100, default 20).
   * @option agent-timeout
   *   Timeout in seconds per agent request (1–3600, default 180).
   * @usage drush labdoo:translations-update --dry-run
   *   Show how many untranslated strings would be added per language.
   * @usage drush labdoo:translations-update
   *   Add untranslated strings to
   *   translations/custom/labdoo_custom.{langcode}.po.
   */
  public function update(
    array $options = [
      'langcodes' => '',
      'dry-run' => FALSE,
      'output-dir' => '',
      'agent' => '',
      'agent-binary' => '',
      'agent-model' => '',
      'batch-size' => 20,
      'agent-timeout' => 180,
    ],
  ): void {
    $this->io()->title('Untranslated interface strings');
    $workflow = $this->workflow ?? new TranslationWorkflow($this->languageManager, $this->configFactory, $this->moduleHandler, $this->translationAgent, $this->catalog);
    $workflow->update($options, fn(string $message) => $this->io()->text($message));
  }

}
