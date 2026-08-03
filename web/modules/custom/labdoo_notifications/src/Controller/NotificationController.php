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

    // Normalize query parameters to handle potential "amp;" prefixes from HTML-encoded URLs.
    foreach ($request->query->all() as $key => $value) {
      if (strpos($key, 'amp;') === 0) {
        $cleanKey = substr($key, 4);
        if (!$request->query->has($cleanKey)) {
          $request->query->set($cleanKey, $value);
        }
      }
    }

    $langCode = $request->query->get('language') ?: $this->languageManager->getCurrentLanguage()->getId();

    // Get parameters from the request.
    $type = $request->query->get('type');
    $id = $request->query->get('id');

    // Fallback detection from query parameters if type or id is missing.
    if (empty($type)) {
      if ($request->query->has('LAPTOP_ID')) {
        $type = 'dootronic';
        $id = $request->query->get('LAPTOP_ID');
      }
      elseif ($request->query->has('DOOTRIP_ID')) {
        $type = 'dootrip';
        $id = $request->query->get('DOOTRIP_ID');
      }
      elseif ($request->query->has('EMAIL') || $request->query->has('CONTACT_EMAIL')) {
        $type = 'contact';
      }
      elseif ($request->query->has('ACTIVITY_URL')) {
        $type = 'team';
        $activityUrl = $request->query->get('ACTIVITY_URL');
        if (preg_match('/node\/(\d+)/', $activityUrl, $matches)) {
          $id = $matches[1];
        }
      }
      elseif ($request->query->has('USER_URL')) {
        $type = 'user';
        $userUrl = $request->query->get('USER_URL');
        if (preg_match('/(?:node|user)\/(\d+)/', $userUrl, $matches)) {
          $id = $matches[1];
        }
      }
    }

    if (empty($id)) {
      if ($type === 'dootronic' && $request->query->has('LAPTOP_ID')) {
        $id = $request->query->get('LAPTOP_ID');
      }
      elseif ($type === 'dootrip' && $request->query->has('DOOTRIP_ID')) {
        $id = $request->query->get('DOOTRIP_ID');
      }
    }

    if (empty($type) || ($type !== 'contact' && empty($id))) {
      return [
        '#markup' => $this->t('Missing parameters.'),
      ];
    }

    // Determine the template ID based on the notification type.
    $templateId = '';
    $params = [];

    // Initialize $params with all uppercase query parameter keys to cover everything passed in.
    foreach ($request->query->all() as $key => $value) {
      $params[strtoupper($key)] = $value;
    }

    switch ($type) {
      case 'contact':
        $templateId = 'contact_form_submitted';
        foreach (['NAME', 'EMAIL', 'SUBJECT', 'MESSAGE', 'REASON', 'USERNAME', 'USEREMAIL', 'COUNTRY', 'CITY', 'CAMPAIGN'] as $paramName) {
          if (!isset($params[$paramName])) {
            $params[$paramName] = $request->query->get($paramName) ?? $request->query->get(strtolower($paramName)) ?? '';
          }
        }
        break;

      case 'dootronic':
        $templateId = 'laptop_updated';
        if (empty($params['LAPTOP_ID'])) {
          $params['LAPTOP_ID'] = $id;
        }
        if (empty($params['LAPTOP_URL'])) {
          $params['LAPTOP_URL'] = $this->getBaseUrl() . '/node/' . $id;
        }

        // Load the node to populate ID and STATUS robustly if they are missing
        $node = \Drupal\node\Entity\Node::load($id);
        if ($node && $node->bundle() === 'dootronic') {
          if (empty($params['ID'])) {
            $params['ID'] = $node->label();
          }
          if (empty($params['STATUS'])) {
            $statusVal = $node->hasField('field_dootronic_status') ? $node->get('field_dootronic_status')->value : NULL;
            $statusLabel = $statusVal;
            if ($node->hasField('field_dootronic_status') && !$node->get('field_dootronic_status')->isEmpty()) {
              $allowed_values = $node->getFieldDefinition('field_dootronic_status')->getSetting('allowed_values');
              if (isset($allowed_values[$statusVal])) {
                $statusLabel = $allowed_values[$statusVal];
              }
            }
            $params['STATUS'] = $statusLabel;
          }
        }

        // Fallbacks in case the node could not be loaded or fields were empty
        if (empty($params['ID'])) {
          $params['ID'] = $params['LAPTOP_TITLE'] ?? '';
        }
        if (empty($params['STATUS'])) {
          $params['STATUS'] = $params['LAPTOP_STATUS'] ?? '';
        }
        break;

      case 'dootrip':
        $templateId = 'dootrip_added';
        if (empty($params['DOOTRIP_ID'])) {
          $params['DOOTRIP_ID'] = $id;
        }
        if (empty($params['DOOTRIP_URL'])) {
          $params['DOOTRIP_URL'] = $this->getBaseUrl() . '/node/' . $id;
        }
        break;

      case 'team':
        $templateId = 'team_activity';
        if (empty($params['ACTIVITY_URL'])) {
          $params['ACTIVITY_URL'] = $this->getBaseUrl() . '/node/' . $id;
        }
        break;

      case 'user':
        $templateId = 'user_created';
        if (empty($params['USER_URL'])) {
          $params['USER_URL'] = $this->getBaseUrl() . '/user/' . $id;
        }
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
    $themeRegistry = \Drupal::service('theme.registry')->get();
    if (!$this->moduleHandler()->moduleExists('labdoo_notifications') || !isset($themeRegistry['notification_email'])) {
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