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
use Drupal\geocoder\GeocoderInterface;
use Drupal\node\NodeInterface;

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
   * @param \Drupal\geocoder\GeocoderInterface $geocoder
   *   The geocoder service.
   */
  public function __construct(
    protected AccountInterface $currentUser,
    protected LanguageManagerInterface $languageManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    protected EmailProcessor $emailProcessor,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected GeocoderInterface $geocoder
  ) {
    $this->logger = $loggerChannelFactory->get('labdoo_notifications');
  }

  /**
   * Sends a laptop event notification.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The laptop node.
   * @param string $operation
   *   The operation being performed ('presave', 'insert', 'update').
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function sendLaptopEventEmail(EntityInterface $node, string $operation = 'presave'): void {
    if (\Drupal::state()->get('labdoo_migrate.disable_indexing', FALSE)) {
      return;
    }

    if ($node->getEntityTypeId() !== 'node' || $node->bundle() !== 'dootronic') {
      return;
    }

    $langCode = $this->getUserPreferredLanguage();
    $emailParams = ['type' => 'LAPTOP_EVENT'];

    // Determine the event type based on the laptop status.
    $status = $node->hasField('field_dootronic_status') ? $node->get('field_dootronic_status')->value : NULL;
    $isNew = $operation === 'insert' || ($operation === 'presave' && $node->isNew());
    $isUpdated = ($operation === 'update' || ($operation === 'presave' && !$node->isNew())) && $node->hasField('field_dootronic_status') && !empty($node->original) && $node->get('field_dootronic_status')->value != $node->original->get('field_dootronic_status')->value;

    if ($isNew && $status == 'S0') {
      // New laptop tagged
      $subjectTemplate = $this->emailProcessor->loadTemplate($langCode, 'laptop_tagged_subject');
      $bodyTemplate = $this->emailProcessor->loadTemplate($langCode, 'laptop_tagged_body');
      $eventType = 'tagged';
    }
    elseif ($status == 'S4' && $isUpdated) {
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

    // Get status label
    $statusLabel = $status;
    if ($node->hasField('field_dootronic_status') && !$node->get('field_dootronic_status')->isEmpty()) {
      $allowed_values = $node->getFieldDefinition('field_dootronic_status')->getSetting('allowed_values');
      if (isset($allowed_values[$status])) {
        $statusLabel = $allowed_values[$status];
      }
    }

    $edoovillageUrl = '';
    if ($node->hasField('field_edoovillage') && !$node->get('field_edoovillage')->isEmpty()) {
      $edoovillageId = $node->get('field_edoovillage')->target_id;
      $edoovillageUrl = Url::fromRoute('entity.node.canonical', ['node' => $edoovillageId], ['absolute' => TRUE])->toString();
    }

    // Prepare email parameters
    $params = [
      'LAPTOP_ID' => $laptopId,
      'LAPTOP_TITLE' => $laptopTitle,
      'LAPTOP_URL' => $laptopUrl,
      'LAPTOP_STATUS' => $status,
      'ID' => $laptopTitle,
      'STATUS' => $statusLabel,
      'EDOOVILLAGE_URL' => $edoovillageUrl,
      'type' => 'dootronic',
      'id' => $laptopId,
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
    if (\Drupal::state()->get('labdoo_migrate.disable_indexing', FALSE)) {
      return;
    }

    if ($node->getEntityTypeId() !== 'node' || $node->bundle() !== 'dootrip') {
      return;
    }

    $langCode = $this->getUserPreferredLanguage($node);
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
    $origin = '';
    if ($node->hasField('field_origin_of_the_trip') && !$node->get('field_origin_of_the_trip')->isEmpty()) {
      $origin = $node->get('field_origin_of_the_trip')->first()->value;
    }
    elseif ($node->hasField('field_origin') && !$node->get('field_origin')->isEmpty()) {
      $origin = $node->get('field_origin')->first()->value;
    }

    $destination = '';
    if ($node->hasField('field_destination_of_the_trip') && !$node->get('field_destination_of_the_trip')->isEmpty()) {
      $destination = $node->get('field_destination_of_the_trip')->first()->value;
    }
    elseif ($node->hasField('field_destination') && !$node->get('field_destination')->isEmpty()) {
      $destination = $node->get('field_destination')->first()->value;
    }

    // Get recipient email addresses
    $mailConfig = $this->configFactory->get('system.site')->get('mail');
    $emailsList = $mailConfig ?: '';

    // Add the travelers and related users
    $emailsList .= $this->getDootripRelatedEmails($node);

    // For announcements, add hub and edoovillage managers in the destination country
    if ($eventType === 'announce') {
      $destinationCountryCode = $this->getDootripDestinationCountryCode($node);
      if ($destinationCountryCode) {
        $emailsList .= $this->getManagersByCountry($destinationCountryCode);
      }
    }

    // Prepare email parameters
    $params = [
      'DOOTRIP_ID' => $dootripId,
      'DOOTRIP_TITLE' => $dootripTitle,
      'DOOTRIP_URL' => $dootripUrl,
      'ORIGIN' => $origin,
      'DESTINATION' => $destination,
      'type' => 'dootrip',
      'id' => $dootripId,
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
    if (\Drupal::state()->get('labdoo_migrate.disable_indexing', FALSE)) {
      return;
    }

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
    if ($node->hasField('field_team') && !$node->get('field_team')->isEmpty()) {
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
      'type' => 'team',
      'id' => $node->id(),
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
      'type' => 'user',
      'id' => $userId,
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
   * @param \Drupal\Core\Entity\EntityInterface|null $node
   *   Optional node to get the language from.
   *
   * @return string
   *   The language code.
   */
  protected function getUserPreferredLanguage(EntityInterface $node = NULL): string {
    if ($node instanceof NodeInterface && $node->bundle() === 'dootrip') {
      // Try to get language from the first traveler
      if ($node->hasField('field_dootripper_s') && !$node->get('field_dootripper_s')->isEmpty()) {
        $item = $node->get('field_dootripper_s')->first();
        if ($item && $item->target_id) {
          $user = $this->entityTypeManager->getStorage('user')->load($item->target_id);
          if ($user instanceof UserInterface) {
            return $user->getPreferredLangcode();
          }
        }
      }
      // Fallback to author
      if ($author = $node->getOwner()) {
        return $author->getPreferredLangcode();
      }
    }

    $langCode = $this->currentUser->getPreferredLangcode();
    return $langCode ?: $this->languageManager->getDefaultLanguage()->getId();
  }

  /**
   * Gets the email addresses of travelers and related users for a dootrip.
   */
  protected function getDootripRelatedEmails(EntityInterface $node): string {
    $emails = [];

    // Add author
    if ($author = $node->getOwner()) {
      $emails[] = $author->getEmail();
    }

    // Add travelers (field_dootripper_s)
    if ($node->hasField('field_dootripper_s')) {
      foreach ($node->get('field_dootripper_s') as $item) {
        if ($item->target_id) {
          $user = $this->entityTypeManager->getStorage('user')->load($item->target_id);
          if ($user instanceof UserInterface) {
            $emails[] = $user->getEmail();
          }
        }
      }
    }

    $emails = array_filter(array_unique($emails));
    return $emails ? ',' . implode(',', $emails) : '';
  }

  /**
   * Gets the destination country code for a dootrip.
   */
  protected function getDootripDestinationCountryCode(EntityInterface $node): ?string {
    // Try field_destination_of_the_trip first
    if ($node->hasField('field_destination_of_the_trip') && !$node->get('field_destination_of_the_trip')->isEmpty()) {
      $value = $node->get('field_destination_of_the_trip')->first()->getValue();
      if (!empty($value['lat']) && !empty($value['lon'])) {
        return $this->getCountryCodeFromCoords($value['lat'], $value['lon']);
      }
    }
    // Then field_locations
    if ($node->hasField('field_locations') && !$node->get('field_locations')->isEmpty()) {
      $value = $node->get('field_locations')->first()->getValue();
      if (!empty($value['lat']) && !empty($value['lon'])) {
        return $this->getCountryCodeFromCoords($value['lat'], $value['lon']);
      }
    }

    return NULL;
  }

  /**
   * Helper to get country code from coordinates.
   */
  protected function getCountryCodeFromCoords($lat, $lon): ?string {
    try {
      $addressCollection = $this->geocoder->reverse((string) $lat, (string) $lon, ['googlemaps']);
      if ($addressCollection && !$addressCollection->isEmpty()) {
        $address = $addressCollection->first();
        if ($country = $address->getCountry()) {
          return $country->getCode();
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error geocoding dootrip: @message', ['@message' => $e->getMessage()]);
    }
    return NULL;
  }

  /**
   * Gets the emails of hub and edoovillage managers in a specific country.
   */
  protected function getManagersByCountry(string $countryCode): string {
    $emails = [];

    // Find edoovillages in this country.
    $edoovillageNids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'edoovillage')
      ->condition('field_country', $countryCode)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($edoovillageNids)) {
      $edoovillages = $this->entityTypeManager->getStorage('node')->loadMultiple($edoovillageNids);
      foreach ($edoovillages as $edoovillage) {
        if ($owner = $edoovillage->getOwner()) {
          $emails[] = $owner->getEmail();
        }
        // Also add additional editors/managers if any
        if ($edoovillage->hasField('field_edoo_additional_editors')) {
          foreach ($edoovillage->get('field_edoo_additional_editors') as $item) {
            if ($item->target_id) {
              $user = $this->entityTypeManager->getStorage('user')->load($item->target_id);
              if ($user instanceof UserInterface) {
                $emails[] = $user->getEmail();
              }
            }
          }
        }
      }
    }

    // Find hubs in this country.
    $hubNids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'hub')
      ->condition('field_country', $countryCode)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($hubNids)) {
      $hubs = $this->entityTypeManager->getStorage('node')->loadMultiple($hubNids);
      foreach ($hubs as $hub) {
        if ($owner = $hub->getOwner()) {
          $emails[] = $owner->getEmail();
        }
        if ($hub->hasField('field_hub_additional_editors')) {
          foreach ($hub->get('field_hub_additional_editors') as $item) {
            if ($item->target_id) {
              $user = $this->entityTypeManager->getStorage('user')->load($item->target_id);
              if ($user instanceof UserInterface) {
                $emails[] = $user->getEmail();
              }
            }
          }
        }
      }
    }

    $emails = array_filter(array_unique($emails));
    return $emails ? ',' . implode(',', $emails) : '';
  }

}
