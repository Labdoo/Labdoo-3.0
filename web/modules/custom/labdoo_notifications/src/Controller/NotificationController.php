<?php

namespace Drupal\labdoo_notifications\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\labdoo_notifications\Service\EmailProcessor;

/**
 * Controller for notification-related pages.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class NotificationController extends ControllerBase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The email processor service.
   *
   * @var \Drupal\labdoo_notifications\Service\EmailProcessor
   */
  protected EmailProcessor $emailProcessor;

  /**
   * Constructs a new NotificationController.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\labdoo_notifications\Service\EmailProcessor $email_processor
   *   The email processor service.
   */
  public function __construct(
    RequestStack $request_stack,
    LanguageManagerInterface $language_manager,
    EmailProcessor $email_processor
  ) {
    $this->requestStack = $request_stack;
    $this->languageManager = $language_manager;
    $this->emailProcessor = $email_processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('language_manager'),
      $container->get('labdoo_notifications.email_processor')
    );
  }

  /**
   * Displays an email notification.
   *
   * @return array
   *   A render array.
   */
  public function displayNotificationEmail(): array {
    $request = $this->requestStack->getCurrentRequest();
    $langCode = $request->query->get('language') ?: $this->languageManager->getCurrentLanguage()->getId();

    // Get parameters from the request.
    $type = $request->query->get('type');
    $id = $request->query->get('id');

    if (empty($type) || ($type !== 'contact' && empty($id))) {
      return [
        '#markup' => $this->t('Missing parameters.'),
      ];
    }

    // Determine the template ID based on the notification type.
    $templateId = '';
    $params = [];

    switch ($type) {
      case 'contact':
        $templateId = 'contact_form_submitted';
        foreach (['NAME', 'EMAIL', 'SUBJECT', 'MESSAGE', 'REASON', 'USERNAME', 'USEREMAIL', 'COUNTRY', 'CITY', 'CAMPAIGN'] as $paramName) {
          $params[$paramName] = $request->query->get($paramName) ?? $request->query->get(strtolower($paramName)) ?? '';
        }
        break;

      case 'laptop':
        $templateId = 'laptop_updated';
        $params['LAPTOP_ID'] = $id;
        $params['LAPTOP_URL'] = $this->getBaseUrl() . '/node/' . $id;
        break;

      case 'dootrip':
        $templateId = 'dootrip_added';
        $params['DOOTRIP_ID'] = $id;
        $params['DOOTRIP_URL'] = $this->getBaseUrl() . '/node/' . $id;
        break;

      case 'team':
        $templateId = 'team_activity';
        $params['ACTIVITY_URL'] = $this->getBaseUrl() . '/node/' . $id;
        break;

      case 'user':
        $templateId = 'user_created';
        $params['USER_URL'] = $this->getBaseUrl() . '/user/' . $id;
        break;

      default:
        return [
          '#markup' => $this->t('Unknown notification type.'),
        ];
    }

    // Load the email template.
    $subjectTemplate = $this->emailProcessor->loadTemplate($langCode, $templateId . '_subject');
    $bodyTemplate = $this->emailProcessor->loadTemplate($langCode, $templateId . '_body');

    if (empty($subjectTemplate) || empty($bodyTemplate)) {
      return [
        '#markup' => $this->t('Email template not found.'),
      ];
    }

    // Process the subject and body.
    $subject = $subjectTemplate;
    $body = $bodyTemplate;
    $this->emailProcessor->processParameters($params, $subject);
    $this->emailProcessor->processParameters($params, $body);

    // Format the body for display.
    $body = nl2br($body);

    // Build the render array.
    $build = [
      '#theme' => 'notification_email',
      '#subject' => $subject,
      '#body' => $body,
    ];

    // If the theme hook is not defined, fall back to a simple markup.
    if (!$this->moduleHandler()->moduleExists('labdoo_notifications') || !$this->themeManager()->themeExists('notification_email')) {
      $build = [
        '#markup' => '<h2>' . $subject . '</h2><div>' . $body . '</div>',
      ];
    }

    return $build;
  }

  /**
   * Gets the base URL of the site.
   *
   * @return string
   *   The base URL.
   */
  protected function getBaseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request->getSchemeAndHttpHost();
  }

}