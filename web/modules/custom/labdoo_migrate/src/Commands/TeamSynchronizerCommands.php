<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drupal\labdoo_migrate\Services\Media\FileManagerInterface;
use Drupal\labdoo_migrate\Services\Media\MediaManagerInterface;
use Drupal\labdoo_migrate\Traits\TextFormatMapperTrait;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Content synchronization commands for Labdoo Teams (Organic Groups).
 *
 * This command synchronizes Organic Groups from Drupal 7 to the custom team
 * structure in Drupal 10. It migrates:
 * - team_page content types to team content types
 * - team members from og_membership table to field_team_members
 * - team pictures from field_image to field_team_picture
 * - team roles from og_role table
 * - role permissions from og_role_permission table
 * - user roles from og_users_roles table
 * - membership types from og_membership_type table
 *
 * Usage:
 * drush labdoo-synchronize-teams
 * drush labdoo-sync-teams --nids=123,456,789 --limit=10 --dry-run
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class TeamSynchronizerCommands extends DrushCommands {

  use TextFormatMapperTrait;

  private const TEAM_CONTENT_TYPE = 'team';
  private const TEAM_POST_CONTENT_TYPE = 'team_post';
  private const TEAM_TASK_CONTENT_TYPE = 'task_team';

  /**
   * The start time.
   *
   * @var mixed
   */
  private $startTime;

  /**
   * The nids.
   *
   * @var mixed
   */
  private $nids;

  /**
   * The limit.
   *
   * @var mixed
   */
  private $limit;

  /**
   * The dry-run mode.
   *
   * @var mixed
   */
  private $dryRun;

  /**
   * Optional UNIX timestamp filter for source nodes.
   *
   * @var int|null
   */
  private ?int $fromTimestamp = NULL;

  /**
   * The progress bar.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar
   */
  private $progressBar;

  /**
   * TeamSynchronizerCommands constructor.
   */
  public function __construct(
    protected ConnectionManagerInterface $externalConnectionManager,
    protected FileManagerInterface $fileManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MediaManagerInterface $mediaManager,
    protected MigrationTrackerInterface $migrationTracker
  ) {
    parent::__construct();
  }

  /**
   * Synchronizes Labdoo Teams (Organic Groups) taking a Drupal 7 instance as a source.
   *
   * @param array $options
   *   Command options.
   *
   * @command labdoo-synchronize-teams [nids=123,456,789] [limit=9] [dry-run] [from-date="YYYY-MM-DD HH:MM:SS"]
   * @aliases labdoo-sync-teams
   * @usage labdoo-synchronize-teams
   *   Synchronizes the Organic Groups from Drupal 7 to Drupal 10 team structure.
   *
   * @option nids List of Drupal 7 group IDs to synchronize.
   * @option limit Limits the execution to the given elements.
   * @option dry-run Whether to run this command in dry-run mode. Specify this parameter to activate the dry-run mode.
   * @option from-date Date/time lower bound to filter source nodes by created/updated (format: "YYYY-MM-DD HH:MM:SS").
   */
  public function startSync(
    array $options = [
      'nids' => NULL,
      'limit' => -1,
      'dry-run' => FALSE,
      'from-date' => NULL,
    ]
  ): void {
    try {
      $this->setEnvironment($options);
      $sourceGroups = $this->getSourceGroups();
      $this->externalConnectionManager->restoreConnection();
      $result = $this->updateDestinationEntities($sourceGroups);

      // Migrate team posts
      $sourcePosts = $this->getSourceTeamPosts();
      $this->externalConnectionManager->restoreConnection();
      $postResult = $this->updateDestinationTeamPosts($sourcePosts);

      $totalCreated = $result['created'] + $postResult['created'];
      $totalUpdated = $result['updated'] + $postResult['updated'];
      $this->tearDown($totalCreated, $totalUpdated);
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * Sets the environment.
   *
   * @param array $options
   *   Command options.
   *
   * @throws \Exception
   */
  protected function setEnvironment(array $options): void {
    $this->fromTimestamp = NULL;
    $this->logger->notice('Setting the environment...');
    $this->startTime = microtime(TRUE);
    if ($options['nids'] !== NULL) {
      $this->nids = explode(',', $options['nids']);
    }
    $this->limit = $options['limit'];
    $this->dryRun = $options['dry-run'];
    if (!empty($options['from-date'])) {
      $ts = strtotime($options['from-date']);
      if ($ts === FALSE) {
        $this->logger->error(sprintf('Invalid value for option "from-date": %s. Expected format: YYYY-MM-DD HH:MM:SS', $options['from-date']));
        die;
      }
      $this->fromTimestamp = (int) $ts;
    }
  }

  /**
   * Retrieves the source groups from Drupal 7.
   *
   * @return array
   *   Returns an array of source groups.
   *
   * @throws \Exception
   */
  protected function getSourceGroups(): array {
    $this->logger->notice('Retrieving the source groups...');

    $groupsResult = [];
    $groupsQuery = $this->externalConnectionManager
      ->setConnection()
      ->select('node', 'n')
      ->fields('n', ['nid', 'title', 'uid', 'status', 'created', 'changed'])
      ->condition('type', 'team');
    if ($this->nids !== NULL) {
      $groupsQuery->condition('nid', $this->nids, 'IN');
    }
    if ($this->limit > -1) {
      $groupsQuery->range(0, $this->limit);
    }
    if ($this->fromTimestamp !== NULL) {
      $or = $groupsQuery->orConditionGroup()
        ->condition('created', $this->fromTimestamp, '>=')
        ->condition('changed', $this->fromTimestamp, '>=');
      $groupsQuery->condition($or);
    }
    $groups = $groupsQuery->execute()->fetchAll();

    foreach ($groups as $group) {
      // Get the group description (body field)
      $body = $this->externalConnectionManager
        ->setConnection()
        ->select('field_data_body', 'fdb')
        ->fields('fdb', ['body_value', 'body_summary', 'body_format'])
        ->condition('entity_type', 'node')
        ->condition('bundle', 'team')
        ->condition('entity_id', $group->nid)
        ->execute()
        ->fetchObject();

      // Get group members from og_membership table
      $membersQuery = $this->externalConnectionManager
        ->setConnection()
        ->select('og_membership', 'ogm')
        ->fields('ogm', ['etid', 'id'])
        ->condition('entity_type', 'user')
        ->condition('gid', $group->nid)
        ->condition('group_type', 'node')
        ->condition('state', 1); // Active memberships
      $membersResult = $membersQuery->execute()->fetchAllKeyed(1, 0);
      $members = array_values($membersResult);

      // Get membership types from og_membership_type table
      $membershipTypes = [];
      try {
        $membershipTypesQuery = $this->externalConnectionManager
          ->setConnection()
          ->select('og_membership_type', 'omt')
          ->fields('omt', ['name', 'description']);
        $membershipTypes = $membershipTypesQuery->execute()->fetchAllAssoc('name', \PDO::FETCH_ASSOC);
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not retrieve membership types: ' . $e->getMessage());
      }

      // Get roles from og_role table
      $roles = [];
      try {
        $rolesQuery = $this->externalConnectionManager
          ->setConnection()
          ->select('og_role', 'ogr')
          ->fields('ogr', ['rid', 'name', 'gid'])
          ->condition('group_type', 'node')
          ->condition('group_bundle', 'team');
        if ($group->nid) {
          $rolesQuery->condition(
            $this->externalConnectionManager->setConnection()->condition('OR')
              ->condition('gid', 0) // Global roles
              ->condition('gid', $group->nid) // Group-specific roles
          );
        }
        $roles = $rolesQuery->execute()->fetchAllAssoc('rid', \PDO::FETCH_ASSOC);
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not retrieve roles: ' . $e->getMessage());
      }

      // Get role permissions from og_role_permission table
      $rolePermissions = [];
      if (!empty($roles)) {
        try {
          $rolePermissionsQuery = $this->externalConnectionManager
            ->setConnection()
            ->select('og_role_permission', 'ogrp')
            ->fields('ogrp', ['rid', 'permission', 'module'])
            ->condition('rid', array_keys($roles), 'IN');
          $rolePermissionsResult = $rolePermissionsQuery->execute()->fetchAll(\PDO::FETCH_ASSOC);

          // Group permissions by role
          foreach ($rolePermissionsResult as $permission) {
            if (!isset($rolePermissions[$permission['rid']])) {
              $rolePermissions[$permission['rid']] = [];
            }
            $rolePermissions[$permission['rid']][] = [
              'permission' => $permission['permission'],
              'module' => $permission['module'],
            ];
          }
        }
        catch (\Exception $e) {
          $this->logger->warning('Could not retrieve role permissions: ' . $e->getMessage());
        }
      }

      // Get user roles from og_users_roles table
      $userRoles = [];
      if (!empty($roles) && !empty($members)) {
        try {
          $userRolesQuery = $this->externalConnectionManager
            ->setConnection()
            ->select('og_users_roles', 'ogur')
            ->fields('ogur', ['uid', 'rid'])
            ->condition('rid', array_keys($roles), 'IN')
            ->condition('uid', $members, 'IN')
            ->condition('gid', $group->nid);
          $userRolesResult = $userRolesQuery->execute()->fetchAll(\PDO::FETCH_ASSOC);

          // Group roles by user
          foreach ($userRolesResult as $userRole) {
            if (!isset($userRoles[$userRole['uid']])) {
              $userRoles[$userRole['uid']] = [];
            }
            $userRoles[$userRole['uid']][] = $userRole['rid'];
          }
        }
        catch (\Exception $e) {
          $this->logger->warning('Could not retrieve user roles: ' . $e->getMessage());
        }
      }

      // Get group picture if available
      $picture = NULL;
      $pictureQuery = $this->externalConnectionManager
        ->setConnection()
        ->select('field_data_field_image', 'fdi')
        ->fields('fdi', ['field_image_fid', 'field_image_alt', 'field_image_title'])
        ->condition('entity_type', 'node')
        ->condition('bundle', 'team')
        ->condition('entity_id', $group->nid);
      $pictureData = $pictureQuery->execute()->fetchObject();

      if ($pictureData) {
        $file = $this->externalConnectionManager
          ->setConnection()
          ->select('file_managed', 'fm')
          ->fields('fm', ['uri', 'filename'])
          ->condition('fid', $pictureData->field_image_fid)
          ->execute()
          ->fetch();

        if ($file) {
          $picture = [
            'fid' => $pictureData->field_image_fid,
            'alt' => $pictureData->field_image_alt,
            'title' => $pictureData->field_image_title,
            'uri' => $file->uri,
            'name' => $file->filename,
            'content' => $this->fileManager->getFileContents($file->uri, TRUE, TRUE),
          ];
        }
      }

      $groupsResult[] = [
        'nid' => $group->nid,
        'title' => $group->title,
        'uid' => $group->uid,
        'status' => $group->status,
        'created' => $group->created,
        'changed' => $group->changed,
        'body' => $body ? [
          'value' => $body->body_value,
          'summary' => $body->body_summary,
          'format' => $body->body_format,
        ] : NULL,
        'members' => $members,
        'picture' => $picture,
        'membership_types' => $membershipTypes,
        'roles' => $roles,
        'role_permissions' => $rolePermissions,
        'user_roles' => $userRoles,
      ];
    }

    $message = sprintf(
      '%d source groups found.',
      count($groupsResult)
    );
    $this->logger->notice($message);

    return $groupsResult;
  }

  /**
   * Updates the destination entities with the source values.
   *
   * @param array $sourceGroups
   *   The source groups.
   *
   * @return array
   *   Returns the number of created/updated entities.
   *
   * @throws \Exception
   */
  protected function updateDestinationEntities(array $sourceGroups): array {
    $this->logger->notice('Creating/Updating the destination entities...');

    $created = 0;
    $updated = 0;

    // Initialize progress bar
    $this->initProgressBar(count($sourceGroups), 'Processing teams');

    foreach ($sourceGroups as $sourceGroup) {
      // Check if the team already exists by NID
      $destinationEntity = $this->entityTypeManager
        ->getStorage('node')
        ->load($sourceGroup['nid']);

      if (empty($destinationEntity)) {
        // Clean up any orphaned field data for this NID
        $this->cleanOrphanedFieldData($sourceGroup['nid']);

        // Create a new team with the original node ID
        $destinationEntity = $this->entityTypeManager
          ->getStorage('node')
          ->create([
            'type' => self::TEAM_CONTENT_TYPE,
            'nid' => $sourceGroup['nid'],
          ]);
        ++$created;
      }
      else {
        // Update existing team
        ++$updated;
      }

      // Set basic fields
      $destinationEntity->set('title', $sourceGroup['title']);
      $destinationEntity->set('uid', $sourceGroup['uid']);
      $destinationEntity->set('status', $sourceGroup['status']);
      $destinationEntity->set('created', $sourceGroup['created']);
      $destinationEntity->setChangedTime($sourceGroup['changed']);

      if ($destinationEntity instanceof \Drupal\node\Entity\Node) {
        $destinationEntity->setNewRevision(FALSE);
      }

      // Set description field
      if (!empty($sourceGroup['body'])) {
        $destinationEntity->set('field_description', [
          'value' => $sourceGroup['body']['value'],
          'summary' => $sourceGroup['body']['summary'],
          'format' => $this->mapFormat($sourceGroup['body']['format']),
        ]);
      }

      // Set team members
      if (!empty($sourceGroup['members'])) {
        $destinationEntity->set('field_team_members', $sourceGroup['members']);
      }

      // Set team picture
      if (!empty($sourceGroup['picture']) && !empty($sourceGroup['picture']['content'])) {
        $file = $this->fileManager->createFile(
          $sourceGroup['picture']['uri'],
          $sourceGroup['picture']['name'],
          $sourceGroup['picture']['content']
        );

        if ($file) {
          $destinationEntity->set('field_team_picture', [
            'target_id' => $file->id(),
            'alt' => $sourceGroup['picture']['alt'] ?? '',
            'title' => $sourceGroup['picture']['title'] ?? '',
          ]);
        }
      }

      // Save the entity if not in dry-run mode
      if (!$this->dryRun) {
        $destinationEntity->save();
        $this->migrationTracker->track(
          'node',
          self::TEAM_CONTENT_TYPE,
          $sourceGroup['nid'],
          (int) $destinationEntity->id()
        );
      }

      // Advance progress bar
      $this->advanceProgressBar();
    }

    return [
      'created' => $created,
      'updated' => $updated,
    ];
  }

  /**
   * Finishes the process.
   *
   * @param int $created
   *   The number of created entities.
   * @param int $updated
   *   The number of updated entities.
   */
  protected function tearDown(int $created, int $updated): void {
    // Finish the progress bar if it exists
    if ($this->progressBar) {
      $this->progressBar->finish();
      $this->output->writeln('');
    }

    $timeElapsedSeconds = microtime(TRUE) - $this->startTime;
    $infoMessage = sprintf(
      "\n\nPROCESS FINISHED:\n"
      . "-- Time elapsed: %s.\n"
      . "-- %d entities created.\n"
      . "-- %d entities updated.",
      gmdate("H:i:s", $timeElapsedSeconds),
      $created,
      $updated
    );
    $this->logger->notice($infoMessage);
  }

  /**
   * Initializes a progress bar.
   *
   * @param int $count
   *   The number of items to process.
   * @param string $message
   *   The message to display.
   */
  protected function initProgressBar(int $count, string $message): void {
    $this->progressBar = new ProgressBar($this->output, $count);
    $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %message%');
    $this->progressBar->setMessage($message);
    $this->progressBar->start();
  }

  /**
   * Advances the progress bar.
   */
  protected function advanceProgressBar(): void {
    if ($this->progressBar) {
      $this->progressBar->advance();
    }
  }


  /**
   * Retrieves the source team posts from Drupal 7.
   *
   * @return array
   *   Returns an array of source team posts.
   *
   * @throws \Exception
   */
  protected function getSourceTeamPosts(): array {
    $this->logger->notice('Retrieving the source team posts...');

    $postsResult = [];
    $postsQuery = $this->externalConnectionManager
      ->setConnection()
      ->select('node', 'n')
      ->fields('n', ['nid', 'title', 'uid', 'status', 'created', 'changed'])
      ->condition('type', 'team_page');
    if ($this->nids !== NULL) {
      $postsQuery->condition('nid', $this->nids, 'IN');
    }
    if ($this->limit > -1) {
      $postsQuery->range(0, $this->limit);
    }
    if ($this->fromTimestamp !== NULL) {
      $or = $postsQuery->orConditionGroup()
        ->condition('created', $this->fromTimestamp, '>=')
        ->condition('changed', $this->fromTimestamp, '>=');
      $postsQuery->condition($or);
    }
    $posts = $postsQuery->execute()->fetchAll();

    foreach ($posts as $post) {
      // Get the post description (body field)
      $body = $this->externalConnectionManager
        ->setConnection()
        ->select('field_data_body', 'fdb')
        ->fields('fdb', ['body_value', 'body_summary', 'body_format'])
        ->condition('entity_type', 'node')
        ->condition('bundle', 'team_page')
        ->condition('entity_id', $post->nid)
        ->execute()
        ->fetchObject();

      // Get comments
      $comments = [];
      try {
        $commentsQuery = $this->externalConnectionManager
          ->setConnection()
          ->select('comment', 'c')
          ->fields('c', ['cid', 'uid', 'subject', 'hostname', 'created', 'changed', 'status', 'thread', 'name', 'mail', 'homepage', 'language'])
          ->condition('nid', $post->nid)
          ->orderBy('cid', 'ASC');
        $commentsData = $commentsQuery->execute()->fetchAll();

        foreach ($commentsData as $comment) {
          $commentBody = $this->externalConnectionManager
            ->setConnection()
            ->select('field_data_comment_body', 'fdcb')
            ->fields('fdcb', ['comment_body_value', 'comment_body_format'])
            ->condition('entity_id', $comment->cid)
            ->condition('entity_type', 'comment')
            ->execute()
            ->fetchObject();

          $comments[] = [
            'cid' => $comment->cid,
            'uid' => $comment->uid,
            'subject' => $comment->subject,
            'created' => $comment->created,
            'changed' => $comment->changed,
            'status' => $comment->status,
            'name' => $comment->name,
            'mail' => $comment->mail,
            'body' => $commentBody ? [
              'value' => $commentBody->comment_body_value,
              'format' => $this->mapFormat($commentBody->comment_body_format),
            ] : NULL,
          ];
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not retrieve comments for post ' . $post->nid . ': ' . $e->getMessage());
      }

      // Get the team reference from og_membership table
      $teamReference = NULL;
      try {
        // In Organic Groups, a node is associated with a group through the og_membership table
        $teamReferenceQuery = $this->externalConnectionManager
          ->setConnection()
          ->select('og_membership', 'ogm')
          ->fields('ogm', ['gid'])
          ->condition('entity_type', 'node')
          ->condition('etid', $post->nid)
          ->condition('group_type', 'node');
        $teamReferenceResult = $teamReferenceQuery->execute()->fetchObject();
        if ($teamReferenceResult) {
          $teamReference = $teamReferenceResult->gid;
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not retrieve team reference for post ' . $post->nid . ': ' . $e->getMessage());
      }

      // Get post picture if available
      $attachment = NULL;
      $attachmentQuery = $this->externalConnectionManager
        ->setConnection()
        ->select('field_data_field_conversation_attachment', 'fca')
        ->fields('fca', ['field_conversation_attachment_fid', 'field_conversation_attachment_description'])
        ->condition('entity_type', 'node')
        ->condition('bundle', 'team_page')
        ->condition('entity_id', $post->nid);
      $attachmentData = $attachmentQuery->execute()->fetchObject();

      if ($attachmentData) {
        $file = $this->externalConnectionManager
          ->setConnection()
          ->select('file_managed', 'fm')
          ->fields('fm', ['uri', 'filename'])
          ->condition('fid', $attachmentData->field_conversation_attachment_fid)
          ->execute()
          ->fetch();

        if ($file) {
          $attachment = [
            'fid' => $attachmentData->field_conversation_attachment_fid,
            'description' => $attachmentData->field_conversation_attachment_description,
            'uri' => $file->uri,
            'name' => $file->filename,
            'content' => $this->fileManager->getFileContents($file->uri, TRUE, TRUE),
          ];
        }
      }

      $postsResult[] = [
        'nid' => $post->nid,
        'title' => $post->title,
        'uid' => $post->uid,
        'status' => $post->status,
        'created' => $post->created,
        'changed' => $post->changed,
        'body' => $body ? [
          'value' => $body->body_value,
          'summary' => $body->body_summary,
          'format' => $body->body_format,
        ] : NULL,
        'team_reference' => $teamReference,
        'attachment' => $attachment,
        'comments' => $comments,
      ];
    }

    $message = sprintf(
      '%d source team posts found.',
      count($postsResult)
    );
    $this->logger->notice($message);

    return $postsResult;
  }

  /**
   * Updates the destination team post entities with the source values.
   *
   * @param array $sourcePosts
   *   The source team posts.
   *
   * @return array
   *   Returns the number of created/updated entities.
   *
   * @throws \Exception
   */
  protected function updateDestinationTeamPosts(array $sourcePosts): array {
    $this->logger->notice('Creating/Updating the destination team post entities...');

    $created = 0;
    $updated = 0;

    // Initialize progress bar
    $this->initProgressBar(count($sourcePosts), 'Processing team posts');

    foreach ($sourcePosts as $sourcePost) {
      // Check if the team post already exists by NID
      $destinationEntity = $this->entityTypeManager
        ->getStorage('node')
        ->load($sourcePost['nid']);

      if (empty($destinationEntity)) {
        // Clean up any orphaned field data for this NID
        $this->cleanOrphanedFieldData($sourcePost['nid']);

        // Create a new team post with the original node ID
        $destinationEntity = $this->entityTypeManager
          ->getStorage('node')
          ->create([
            'type' => self::TEAM_POST_CONTENT_TYPE,
            'nid' => $sourcePost['nid'],
          ]);
        ++$created;
      }
      else {
        // Update existing team post
        ++$updated;
      }

      // Set basic fields
      $destinationEntity->set('title', $sourcePost['title']);
      $destinationEntity->set('uid', $sourcePost['uid']);
      $destinationEntity->set('status', $sourcePost['status']);
      $destinationEntity->set('created', $sourcePost['created']);
      $destinationEntity->setChangedTime($sourcePost['changed']);

      if ($destinationEntity instanceof \Drupal\node\Entity\Node) {
        $destinationEntity->setNewRevision(FALSE);
      }

      // Set description field
      if (!empty($sourcePost['body'])) {
        $destinationEntity->set('body', [
          'value' => $sourcePost['body']['value'],
          'summary' => $sourcePost['body']['summary'] ?? '',
          'format' => $this->mapFormat($sourcePost['body']['format']),
        ]);
      }

      // Ensure the node exists in storage before creating comments to avoid
      // double inserts into comment_entity_statistics.
      if (!$this->dryRun) {
        $destinationEntity->save();
      }

      // Set comments
      if (!$this->dryRun && !empty($sourcePost['comments'])) {
        foreach ($sourcePost['comments'] as $sourceComment) {
          // Check if comment already exists by subject and created time for this node
          $existingComments = $this->entityTypeManager
            ->getStorage('comment')
            ->loadByProperties([
              'entity_id' => $destinationEntity->id(),
              'entity_type' => 'node',
              'field_name' => 'field_team_comments',
              'subject' => $sourceComment['subject'],
              'created' => $sourceComment['created'],
            ]);

          if (empty($existingComments)) {
            $comment = $this->entityTypeManager
              ->getStorage('comment')
              ->create([
                'comment_type' => 'comment',
                'entity_id' => $destinationEntity->id(),
                'entity_type' => 'node',
                'field_name' => 'field_team_comments',
                'uid' => $sourceComment['uid'],
                'subject' => $sourceComment['subject'],
                'comment_body' => [
                  'value' => $sourceComment['body']['value'] ?? '',
                  'format' => $this->mapFormat($sourceComment['body']['format'] ?? 'basic_html'),
                ],
                'status' => $sourceComment['status'],
                'created' => $sourceComment['created'],
                'changed' => $sourceComment['changed'],
                'name' => $sourceComment['name'],
                'mail' => $sourceComment['mail'],
              ]);
            $comment->save();

            // Track the comment
            $this->migrationTracker->track(
              'comment',
              'comment',
              $sourceComment['cid'],
              (int) $comment->id()
            );
          }
        }
      }

      // Set team reference
      if (!empty($sourcePost['team_reference'])) {
        // Find the corresponding team in the destination system
        $teamEntity = $this->entityTypeManager
          ->getStorage('node')
          ->loadByProperties([
            'type' => self::TEAM_CONTENT_TYPE,
            'nid' => $sourcePost['team_reference'],
          ]);

        if (!empty($teamEntity)) {
          $teamEntity = reset($teamEntity);
          $destinationEntity->set('field_team', $teamEntity->id());
        }
        else {
          $this->logger->warning(sprintf(
            'Could not find team with ID %d for team post %s (ID: %d)',
            $sourcePost['team_reference'],
            $sourcePost['title'],
            $sourcePost['nid']
          ));
        }
      }

      // Set team post attachment
      if (!empty($sourcePost['attachment']) && !empty($sourcePost['attachment']['content'])) {
        // Determine the file type based on the file extension
        $fileExtension = pathinfo($sourcePost['attachment']['name'], PATHINFO_EXTENSION);
        $isImage = in_array(strtolower($fileExtension), ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp']);

        // Set the appropriate media bundle and destination field based on the file type
        $bundle = $isImage ? 'image' : 'document';
        $destinationField = $isImage ? 'field_media_image' : 'field_media_document';
        $metadata = [
          'bundle' => $bundle,
          'destination_field' => $destinationField,
        ];

        try {
          // Check if media entity already exists
          $mediaEntity = $this->fileManager->mediaEntityExists($bundle, $sourcePost['attachment']['name']);

          if ($mediaEntity === NULL) {
            // Create a new media entity if it doesn't exist
            $media = $this->mediaManager->createMedia(
              $sourcePost['attachment']['uri'],
              $sourcePost['attachment']['name'],
              $sourcePost['attachment']['content'],
              $metadata,
              'es' // Default language code
            );
          } else {
            // Use existing media entity
            $media = $mediaEntity;
          }

          if ($media) {
            $destinationEntity->set('field_attachment', [
              'target_id' => $media->id(),
            ]);
          }
        }
        catch (\Exception $e) {
          $this->logger->warning(sprintf(
            'Could not create media entity for team post %s (ID: %d): %s',
            $sourcePost['title'],
            $sourcePost['nid'],
            $e->getMessage()
          ));
        }
      }

      // Save the entity if not in dry-run mode
      if (!$this->dryRun) {
        $destinationEntity->save();
        $this->migrationTracker->track(
          'node',
          self::TEAM_POST_CONTENT_TYPE,
          $sourcePost['nid'],
          (int) $destinationEntity->id()
        );
      }

      // Advance progress bar
      $this->advanceProgressBar();
    }

    return [
      'created' => $created,
      'updated' => $updated,
    ];
  }

  /**
   * Cleans up orphaned field data for a given node ID.
   *
   * @param int $nid
   *   The node ID.
   */
  protected function cleanOrphanedFieldData(int $nid): void {
    $database = \Drupal::database();
    $tables = [
      'node__field_description',
      'node_revision__field_description',
      'node__field_team_members',
      'node_revision__field_team_members',
      'node__field_team_picture',
      'node_revision__field_team_picture',
      'node__body',
      'node_revision__body',
      'node__field_team',
      'node_revision__field_team',
      'node__field_attachment',
      'node_revision__field_attachment',
      'comment_entity_statistics',
    ];

    foreach ($tables as $table) {
      if ($database->schema()->tableExists($table)) {
        $database->delete($table)
          ->condition('entity_id', $nid)
          ->execute();
      }
    }

    // Clean up orphaned comments for this node.
    if ($database->schema()->tableExists('comment_field_data')) {
      $cids = $database->select('comment_field_data', 'cfd')
        ->fields('cfd', ['cid'])
        ->condition('entity_id', $nid)
        ->condition('entity_type', 'node')
        ->execute()
        ->fetchCol();

      if (!empty($cids)) {
        // Comment tables that use 'cid' as the primary key or identifier.
        $comment_core_tables = [
          'comment',
          'comment_field_data',
        ];
        foreach ($comment_core_tables as $table) {
          if ($database->schema()->tableExists($table)) {
            $database->delete($table)
              ->condition('cid', $cids, 'IN')
              ->execute();
          }
        }

        // Comment field tables that use 'entity_id' for the comment ID.
        $comment_field_tables = [
          'comment__comment_body',
        ];
        foreach ($comment_field_tables as $table) {
          if ($database->schema()->tableExists($table)) {
            $database->delete($table)
              ->condition('entity_id', $cids, 'IN')
              ->execute();
          }
        }
      }
    }
  }

}
