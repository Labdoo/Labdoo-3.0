<?php

namespace Drupal\labdoo_notifications\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\user\UserInterface;

/**
 * Service for managing notifications.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class NotificationManager {

  private const TEMPLATES_FOLDER = 'templates/email';

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * NotificationManager constructor.
   *
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_notifications\Service\EmailProcessor $emailProcessor
   *   The email processor service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected AccountInterface $currentUser,
    protected LanguageManagerInterface $languageManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    protected EmailProcessor $emailProcessor,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory
  ) {
    $this->logger = $loggerChannelFactory->get('labdoo_notifications');
  }

  /**
   * Sends a laptop event notification.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The laptop node.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function sendLaptopEventEmail(EntityInterface $node): void {
    if ($node->getEntityTypeId() !== 'node' || $node->bundle() !== 'laptop') {
      return;
    }

    $langCode = $this->getUserPreferredLanguage();
    $emailParams = ['type' => 'LAPTOP_EVENT'];

    // Determine the event type based on the laptop status.
    $status = $node->get('field_status')->value;
    $isNew = $node->isNew();
    $isUpdated = !$isNew && $node->hasField('field_status') && $node->original && $node->get('field_status')->value != $node->original->get('field_status')->value;

    if ($isNew && $status == 'Tagged') {
      // New laptop tagged
      $subjectTemplate = $this->emailProcessor->loadTemplate($langCode, 'laptop_tagged_subject');
      $bodyTemplate = $this->emailProcessor->loadTemplate($langCode, 'laptop_tagged_body');
      $eventType = 'tagged';
    }
    elseif ($status == 'Delivered' && $isUpdated) {
      // Laptop delivered
      $subjectTemplate = $this->emailProcessor->loadTemplate($langCode, 'laptop_delivered_subject');
      $bodyTemplate = $this->emailProcessor->loadTemplate($langCode, 'laptop_delivered_body');
      $eventType = 'delivered';
    }
    elseif ($isUpdated) {
      // Laptop updated
      $subjectTemplate = $this->emailProcessor->loadTemplate($langCode, 'laptop_updated_subject');
      $bodyTemplate = $this->emailProcessor->loadTemplate($langCode, 'laptop_updated_body');
      $eventType = 'updated';
    }
    else {
      // No notification needed
      return;
    }

    if (empty($subjectTemplate) || empty($bodyTemplate)) {
      $this->logger->warning('Email template not found for laptop event: @event', ['@event' => $eventType]);
      return;
    }

    // Get laptop details
    $laptopId = $node->id();
    $laptopTitle = $node->label();
    $laptopUrl = Url::fromRoute('entity.node.canonical', ['node' => $laptopId], ['absolute' => TRUE])->toString();

    // Get recipient email addresses
    $emailsList = $this->configFactory->get('system.site')->get('mail');
    
    // Add laptop manager if available
    if ($node->hasField('field_manager') && !$node->get('field_manager')->isEmpty()) {
      $managerId = $node->get('field_manager')->target_id;
      $manager = $this->entityTypeManager->getStorage('user')->load($managerId);
      if ($manager) {
        $emailsList .= ', ' . $manager->getEmail();
      }
    }

    // Add dootrip manager if available
    if ($node->hasField('field_dootrip') && !$node->get('field_dootrip')->isEmpty()) {
      $dootripId = $node->get('field_dootrip')->target_id;
      $dootrip = $this->entityTypeManager->getStorage('node')->load($dootripId);
      if ($dootrip && $dootrip->getOwnerId()) {
        $dootripManager = $this->entityTypeManager->getStorage('user')->load($dootrip->getOwnerId());
        if ($dootripManager) {
          $emailsList .= ', ' . $dootripManager->getEmail();
        }
      }
    }

    // Add edoovillage manager if available
    if ($node->hasField('field_edoovillage') && !$node->get('field_edoovillage')->isEmpty()) {
      $edoovillageId = $node->get('field_edoovillage')->target_id;
      $edoovillage = $this->entityTypeManager->getStorage('node')->load($edoovillageId);
      if ($edoovillage && $edoovillage->getOwnerId()) {
        $edoovillageManager = $this->entityTypeManager->getStorage('user')->load($edoovillage->getOwnerId());
        if ($edoovillageManager) {
          $emailsList .= ', ' . $edoovillageManager->getEmail();
        }
      }
    }

    // Add the author of the laptop
    $author = $this->entityTypeManager->getStorage('user')->load($node->getOwnerId());
    if ($author) {
      $emailsList .= ', ' . $author->getEmail();
    }

    // Prepare email parameters
    $params = [
      'LAPTOP_ID' => $laptopId,
      'LAPTOP_TITLE' => $laptopTitle,
      'LAPTOP_URL' => $laptopUrl,
      'LAPTOP_STATUS' => $status,
    ];

    // Process the subject and body
    $subject = $subjectTemplate;
    $body = $bodyTemplate;
    $this->emailProcessor->processParameters($params, $subject);
    $this->emailProcessor->processParameters($params, $body);

    // Send the email
    $emailParams['subject'] = $subject;
    $emailParams['body'] = $body;
    $emailParams['to'] = $this->configFactory->get('system.site')->get('mail');
    $emailParams['headers']['Bcc'] = $emailsList;

    $this->emailProcessor->sendEmail($emailParams);
  }

  /**
   * Sends a dootrip event notification.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The dootrip node.
   * @param string $eventType
   *   The event type (insert, update, etc.).
   */
  public function sendDootripEventEmail(EntityInterface $node, string $eventType): void {
    if ($node->getEntityTypeId() !== 'node' || $node->bundle() !== 'dootrip') {
      return;
    }

    $langCode = $this->getUserPreferredLanguage();
    $emailParams = ['type' => 'DOOTRIP_EVENT'];

    // Determine the template based on the event type
    switch ($eventType) {
      case 'insert':
        $subjectTemplate = $this->emailProcessor ? $this->emailProcessor->loadTemplate($langCode, 'dootrip_added_subject') : NULL;
        $bodyTemplate = $this->emailProcessor ? $this->emailProcessor->loadTemplate($langCode, 'dootrip_added_body') : NULL;
        break;
      case 'update':
        $subjectTemplate = $this->emailProcessor ? $this->emailProcessor->loadTemplate($langCode, 'dootrip_updated_subject') : NULL;
        $bodyTemplate = $this->emailProcessor ? $this->emailProcessor->loadTemplate($langCode, 'dootrip_updated_body') : NULL;
        break;
      case 'expired':
        $subjectTemplate = $this->emailProcessor ? $this->emailProcessor->loadTemplate($langCode, 'dootrip_expired_subject') : NULL;
        $bodyTemplate = $this->emailProcessor ? $this->emailProcessor->loadTemplate($langCode, 'dootrip_expired_body') : NULL;
        break;
      case 'announce':
        $subjectTemplate = $this->emailProcessor ? $this->emailProcessor->loadTemplate($langCode, 'dootrip_announce_subject') : NULL;
        $bodyTemplate = $this->emailProcessor ? $this->emailProcessor->loadTemplate($langCode, 'dootrip_announce_body') : NULL;
        break;
      default:
        return;
    }

    if (empty($subjectTemplate) || empty($bodyTemplate)) {
      $this->logger->warning('Email template not found for dootrip event: @event', ['@event' => $eventType]);
      return;
    }

    // Get dootrip details
    $dootripId = $node->id();
    $dootripTitle = $node->label();
    $dootripUrl = Url::fromRoute('entity.node.canonical', ['node' => $dootripId], ['absolute' => TRUE])->toString();

    // Get origin and destination
    $origin = $node->hasField('field_origin') && $node->get('field_origin')->first() ? $node->get('field_origin')->value : '';
    $destination = $node->hasField('field_destination') && $node->get('field_destination')->first() ? $node->get('field_destination')->value : '';

    // Get recipient email addresses
    $mailConfig = $this->configFactory->get('system.site')->get('mail');
    $emailsList = $mailConfig ?: '';

    // Add the author of the dootrip
    $authorId = $node->getOwnerId();
    if ($authorId) {
      $author = $this->entityTypeManager->getStorage('user')->load($authorId);
      if ($author) {
        $emailsList .= ',' . $author->getEmail();
      }
    }

    // For announcements, add hub managers near the origin and destination
    if ($eventType === 'announce') {
      // This would require more complex logic to find nearby hubs
      // For now, we'll just add all hub managers
      $hubManagers = $this->entityTypeManager->getStorage('user')
        ->loadByProperties(['roles' => 'hub_manager']);
      foreach ($hubManagers as $hubManager) {
        $emailsList .= ', ' . $hubManager->getEmail();
      }
    }

    // Prepare email parameters
    $params = [
      'DOOTRIP_ID' => $dootripId,
      'DOOTRIP_TITLE' => $dootripTitle,
      'DOOTRIP_URL' => $dootripUrl,
      'ORIGIN' => $origin,
      'DESTINATION' => $destination,
    ];

    // Process the subject and body
    $subject = $subjectTemplate;
    $body = $bodyTemplate;
    $this->emailProcessor->processParameters($params, $subject);
    $this->emailProcessor->processParameters($params, $body);

    // Send the email
    $emailParams['subject'] = $subject;
    $emailParams['body'] = $body;
    $emailParams['to'] = $this->configFactory->get('system.site')->get('mail');
    $emailParams['headers']['Bcc'] = $emailsList;

    $this->emailProcessor->sendEmail($emailParams);
  }

  /**
   * Sends a team event notification.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The team-related node.
   * @param mixed $comment
   *   The comment entity, if applicable.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function sendTeamEventEmail(EntityInterface $node, $comment = NULL): void {
    $langCode = $this->getUserPreferredLanguage();
    $emailParams = ['type' => 'TEAM_ACTIVITY'];

    $subjectTemplate = $this->emailProcessor->loadTemplate($langCode, 'team_activity_subject');
    $bodyTemplate = $this->emailProcessor->loadTemplate($langCode, 'team_activity_body');

    if (empty($subjectTemplate) || empty($bodyTemplate)) {
      $this->logger->warning('Email template not found for team activity');
      return;
    }

    // Determine activity type and body
    $bundle = $node->bundle();
    $activityType = '';
    $activityBody = '';

    switch ($bundle) {
      case 'team_page':
        if ($comment === NULL) {
          $activityType = 'conversation';
          $activityBody = $node->hasField('body') ? $node->get('body')->value : '';
        }
        else {
          $activityType = 'comment';
          $activityBody = $comment->hasField('comment_body') ? $comment->get('comment_body')->value : '';
        }
        break;
      case 'event':
        $activityType = 'event';
        $activityBody = $node->hasField('body') ? $node->get('body')->value : '';
        break;
      case 'team_task':
        if ($comment === NULL) {
          $activityType = 'task';
          $activityBody = $node->hasField('body') ? $node->get('body')->value : '';
        }
        else {
          $activityType = 'comment';
          $activityBody = $comment->hasField('comment_body') ? $comment->get('comment_body')->value : '';
        }
        break;
      default:
        return;
    }

    $activityTitle = $node->label();
    $activityUrl = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], ['absolute' => TRUE])->toString();
    $teamsMgmUrl = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString() . 'my-teams';

    // Get team information
    $teamName = '';
    $teamId = 0;
    if ($node->hasField('field_team')) {
      $teamId = $node->get('field_team')->target_id;
      $team = $this->entityTypeManager->getStorage('node')->load($teamId);
      if ($team) {
        $teamName = $team->label();
      }
    }

    // Get recipient email addresses
    $emailsList = $this->configFactory->get('system.site')->get('mail');

    // Add team members
    // This would require more complex logic to find team members
    // For now, we'll just add the author of the node
    $author = $this->entityTypeManager->getStorage('user')->load($node->getOwnerId());
    if ($author) {
      $emailsList .= ', ' . $author->getEmail();
    }

    // If it's a comment, add the author of the comment
    if ($comment !== NULL) {
      $commentAuthor = $this->entityTypeManager->getStorage('user')->load($comment->getOwnerId());
      if ($commentAuthor) {
        $emailsList .= ', ' . $commentAuthor->getEmail();
      }
    }

    // Prepare email parameters
    $params = [
      'ACTIVITY_TYPE' => $activityType,
      'ACTIVITY_SUBJECT' => $activityTitle,
      'ACTIVITY_BODY' => $activityBody,
      'TEAM_NAME' => $teamName,
      'ACTIVITY_URL' => $activityUrl,
      'TEAMS_MGM_URL' => $teamsMgmUrl,
      'USERNAME' => $this->currentUser->getDisplayName(),
    ];

    // Process the subject and body
    $subject = $subjectTemplate;
    $body = $bodyTemplate;
    $this->emailProcessor->processParameters($params, $subject);
    $this->emailProcessor->processParameters($params, $body);

    // Send the email
    $emailParams['subject'] = $subject;
    $emailParams['body'] = $body;
    $emailParams['to'] = $this->configFactory->get('system.site')->get('mail');
    $emailParams['headers']['Bcc'] = $emailsList;

    $this->emailProcessor->sendEmail($emailParams);
  }

  /**
   * Sends a user creation notification.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   */
  public function sendUserCreatedEmail(UserInterface $account): void {
    $langCode = $this->getUserPreferredLanguage();
    $emailParams = ['type' => 'USER_CREATED'];

    $subjectTemplate = $this->emailProcessor->loadTemplate($langCode, 'user_created_subject');
    $bodyTemplate = $this->emailProcessor->loadTemplate($langCode, 'user_created_body');

    if (empty($subjectTemplate) || empty($bodyTemplate)) {
      $this->logger->warning('Email template not found for user creation');
      return;
    }

    // Get user details
    $userName = $account->getDisplayName();
    $userMail = $account->getEmail();
    $userId = $account->id();
    $userUrl = Url::fromRoute('entity.user.canonical', ['user' => $userId], ['absolute' => TRUE])->toString();

    // Get recipient email addresses
    $emailsList = $this->configFactory->get('system.site')->get('mail');

    // Add the new user
    $emailsList .= ', ' . $userMail;

    // Prepare email parameters
    $params = [
      'USERNAME' => $userName,
      'USER_MAIL' => $userMail,
      'USER_URL' => $userUrl,
    ];

    // Process the subject and body
    $subject = $subjectTemplate;
    $body = $bodyTemplate;
    $this->emailProcessor->processParameters($params, $subject);
    $this->emailProcessor->processParameters($params, $body);

    // Send the email
    $emailParams['subject'] = $subject;
    $emailParams['body'] = $body;
    $emailParams['to'] = $this->configFactory->get('system.site')->get('mail');
    $emailParams['headers']['Bcc'] = $emailsList;

    $this->emailProcessor->sendEmail($emailParams);
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
