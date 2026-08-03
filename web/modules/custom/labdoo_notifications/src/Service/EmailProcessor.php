<?php

namespace Drupal\labdoo_notifications\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Service for processing emails.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class EmailProcessor {

  private const TEMPLATES_FOLDER = 'templates/email';

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * EmailProcessor constructor.
   *
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected AccountInterface $currentUser,
    protected LanguageManagerInterface $languageManager,
    protected FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory
  ) {
    $this->logger = $loggerChannelFactory->get('labdoo_notifications');
  }

  /**
   * Sends an email notification.
   *
   * @param array $emailParams
   *   The email parameters.
   */
  public function sendEmail(array $emailParams): void {
    // Check if the email has a 'to' field.
    if (empty($emailParams['to'])) {
      $this->logger->warning('Trying to send a message without a \'to\' field');
      return;
    }

    // Check if test mode is enabled.
    $config = $this->configFactory->get('labdoo_notifications.settings');
    $testMode = $config->get('test_mode');
    $testEmail = $config->get('test_email');

    // If test mode is enabled and a test email is set, redirect all emails to the test email.
    if ($testMode && !empty($testEmail)) {
      $this->logger->notice('Test mode is enabled. Redirecting email to @email', ['@email' => $testEmail]);

      // Create a copy of the email parameters with the test email as the recipient.
      $testParams = $emailParams;
      $testParams['to'] = $testEmail;

      // Add original recipients to the email body for reference.
      $originalTo = $emailParams['to'];
      $originalBcc = !empty($emailParams['headers']['Bcc']) ? $emailParams['headers']['Bcc'] : '';

      $testParams['body'] = "**TEST MODE ENABLED**\n\n" .
        "Original To: $originalTo\n" .
        "Original Bcc: $originalBcc\n\n" .
        "-----------------------------------\n\n" .
        $testParams['body'];

      // Remove any Bcc headers as we're sending everything to the test email.
      unset($testParams['headers']['Bcc']);

      // Send the email to the test email address.
      $this->mailManager->mail(
        'labdoo_notifications',
        'notification',
        $testParams['to'],
        $this->languageManager->getDefaultLanguage()->getId(),
        $testParams,
        NULL,
        TRUE
      );

      return;
    }

    // If test mode is not enabled, proceed with normal email sending.

    // If there are multiple recipients in the 'to' field, partition them.
    $toAddresses = $this->partitionEmailAddresses($emailParams['to']);

    // If there are Bcc recipients, partition them as well.
    $bccAddresses = [];
    if (!empty($emailParams['headers']['Bcc'])) {
      $bccAddresses = $this->partitionEmailAddresses($emailParams['headers']['Bcc']);
    }

    // Send the email to each recipient.
    foreach ($toAddresses as $toAddress) {
      $params = $emailParams;
      $params['to'] = $toAddress;

      // Send the email using the mail manager.
      $this->mailManager->mail(
        'labdoo_notifications',
        'notification',
        $params['to'],
        $this->languageManager->getDefaultLanguage()->getId(),
        $params,
        NULL,
        TRUE
      );
    }

    // Send the email to each Bcc recipient.
    if (!empty($bccAddresses)) {
      foreach ($bccAddresses as $bccAddress) {
        $params = $emailParams;
        $params['to'] = $bccAddress;
        unset($params['headers']['Bcc']);

        // Send the email using the mail manager.
        $this->mailManager->mail(
          'labdoo_notifications',
          'notification',
          $params['to'],
          $this->languageManager->getDefaultLanguage()->getId(),
          $params,
          NULL,
          TRUE
        );
      }
    }
  }

  /**
   * Loads an email template.
   *
   * @param string $langCode
   *   The language code.
   * @param string $templateId
   *   The template ID.
   *
   * @return string|null
   *   The template content, or NULL if the template doesn't exist.
   */
  public function loadTemplate(string $langCode, string $templateId): ?string {
    $modulePath = \Drupal::moduleHandler()->getModule('labdoo_notifications')->getPath();
    $path = sprintf(
      '%s/%s/%s/%s-%s.email',
      $modulePath,
      self::TEMPLATES_FOLDER,
      $langCode,
      $templateId,
      $langCode
    );
    $realPath = $this->fileSystem->realpath($path);
    if (empty($realPath) || !file_exists($realPath)) {
      return NULL;
    }

    return file_get_contents($realPath);
  }

  /**
   * Processes parameters in an email body.
   *
   * @param array $params
   *   The parameters to process.
   * @param string $body
   *   The email body to process.
   */
  public function processParameters(array $params, string &$body): void {
    if (empty($params['CONTACT_EMAIL'])) {
      $params['CONTACT_EMAIL'] = $this->configFactory->get('system.site')->get('mail');
    }

    if (empty($params['DASHBOARD_URL'])) {
      // Determine the dynamic base URL of the site to replace the production host.
      $host = '';
      try {
        if (\Drupal::hasRequest()) {
          $host = \Drupal::request()->getSchemeAndHttpHost();
        }
      }
      catch (\Exception $e) {
        // Ignore exceptions if request is not available.
      }
      if (empty($host) || strpos($host, 'http') !== 0) {
        global $base_url;
        $host = $base_url;
      }
      if (empty($host) || strpos($host, 'http') !== 0) {
        $host = 'https://platform.labdoo.org';
      }
      $params['DASHBOARD_URL'] = $host . '/content/getting-started';
    }

    if (strpos($body, '[LANGUAGE_MENU]') !== FALSE) {
      $supportedLanguages = [
        "ca" => "Catalan", 
        "zh-hant" => "Chinese",
        "de" => "Deutsch", 
        "en" => "English", 
        "fr" => "French", 
        "nl" => "Nederlands", 
        "es" => "Spanish",
      ];

      // Build first the language switchers on the header of the email
      // All parameters are part of the hyperlink for each language switch
      $langParamsBase = $params;
      if (!isset($langParamsBase['type']) && isset($params['EMAIL'])) {
        $langParamsBase['type'] = 'contact';
      }

      $htmlCode  = "<hr/>" . t("View this message in other languages:");
      $htmlCode .= "<br/>";

      // Build each language switcher
      foreach ($supportedLanguages as $code => $language) {
        $langParams = $langParamsBase;
        $langParams['language'] = $code;
        $urlParams = "?" . http_build_query($langParams);
        $htmlCode .= "<a href='https://platform.labdoo.org/content/notification-email" . $urlParams . "'>$language</a> | "; 
      }
      $htmlCode = substr($htmlCode, 0, -2);
      $htmlCode .= "<hr/>";

      $body = str_replace("[LANGUAGE_MENU]", $htmlCode, $body);
    }

    foreach ($params as $key => $value) {
      $body = str_replace("[$key]", $value, $body);
    }

    // Determine the dynamic base URL of the site to replace the production host.
    $host = '';
    try {
      if (\Drupal::hasRequest()) {
        $host = \Drupal::request()->getSchemeAndHttpHost();
      }
    }
    catch (\Exception $e) {
      // Ignore exceptions if request is not available.
    }
    if (empty($host) || strpos($host, 'http') !== 0) {
      global $base_url;
      $host = $base_url;
    }
    if (empty($host) || strpos($host, 'http') !== 0) {
      $host = 'https://platform.labdoo.org';
    }

    if (!empty($host) && $host !== 'https://platform.labdoo.org') {
      $body = str_replace('https://platform.labdoo.org', $host, $body);
    }
  }

  /**
   * Partitions email addresses for batch sending.
   *
   * @param string $emailString
   *   The email addresses as a comma-separated string.
   *
   * @return array
   *   An array of email addresses.
   */
  public function partitionEmailAddresses(string $emailString): array {
    $emails = [];

    // Split the email string by commas.
    $emailArray = explode(',', $emailString);

    // Trim each email address and add it to the result array.
    foreach ($emailArray as $email) {
      $trimmedEmail = trim($email);
      if (!empty($trimmedEmail)) {
        $emails[] = $trimmedEmail;
      }
    }

    return $emails;
  }

  /**
   * Gets the path to an email template.
   *
   * @param string $langCode
   *   The language code.
   *
   * @return string
   *   The path to the email templates for the given language.
   */
  public function getTemplatePath(string $langCode): string {
    $modulePath = \Drupal::moduleHandler()->getModule('labdoo_notifications')->getPath();
    return sprintf('%s/%s/%s', $modulePath, self::TEMPLATES_FOLDER, $langCode);
  }

  /**
   * Gets the user's preferred language code.
   *
   * @return string
   *   The language code.
   */
  protected function getUserPreferredLanguage(): string {
    $langCode = $this->currentUser->getPreferredLangcode();

    return $langCode ?: $this->languageManager->getDefaultLanguage()->getId();
  }

}
