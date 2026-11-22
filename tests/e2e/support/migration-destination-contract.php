<?php

/**
 * @file
 * Exercises destination mapping using owned fixtures in the isolated clone.
 */

declare(strict_types=1);

use Drupal\comment\Entity\Comment;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\labdoo_migrate\Model\FieldModel;
use Drupal\labdoo_migrate\Model\MappingModel;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepository;
use Drupal\labdoo_migrate\Services\DestinationContent\UserDestinationRepository;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

require __DIR__ . '/harden.php';

$token = getenv('LABDOO_MIGRATION_TEST_TOKEN');
if (!is_string($token) || !preg_match('/^[a-f0-9]{16}$/', $token)) {
  throw new RuntimeException('A unique migration fixture token is required.');
}
$file = Settings::get('labdoo_e2e_state') . '/migration-destination-' . $token . '.json';
$operation = getenv('LABDOO_MIGRATION_TEST_OP');
if (!in_array($operation, ['create', 'cleanup'], TRUE)) {
  throw new RuntimeException('Unsupported migration fixture operation.');
}
$state = \Drupal::state();
$previous = [];
foreach (['labdoo_migrate_is_running', 'labdoo_migrate.disable_indexing'] as $key) {
  $previous[$key] = $state->get($key);
  $state->set($key, TRUE);
}
try {
  if ($operation === 'cleanup') {
    if (is_file($file)) {
      $owned = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
      foreach (array_reverse($owned) as $record) {
        $entity = \Drupal::entityTypeManager()->getStorage($record['type'])->load($record['id']);
        if ($entity !== NULL) {
          if ($entity->uuid() !== $record['uuid']) {
            throw new RuntimeException('Refusing to delete an unrelated fixture entity.');
          }
          $entity->delete();
        }
      }
      unlink($file);
    }
    echo 'MIGRATION_JSON:' . json_encode(['cleaned' => TRUE], JSON_THROW_ON_ERROR);
    return;
  }
  if (is_file($file)) {
    throw new RuntimeException('Duplicate migration fixture token.');
  }
  $owned = [];
  $remember = static function (ContentEntityInterface $entity) use (&$owned, $file): void {
    $owned[] = ['type' => $entity->getEntityTypeId(), 'id' => $entity->id(), 'uuid' => $entity->uuid()];
    file_put_contents($file, json_encode($owned, JSON_THROW_ON_ERROR));
    chmod($file, 0600);
  };
  $fixtures = json_decode(file_get_contents(Settings::get('labdoo_e2e_state') . '/integration-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  $node = Node::load($fixtures['nodes']['Team Post']['id'])->createDuplicate();
  $node->setOwnerId($fixtures['accounts']['admin']['id']);
  $node->setSyncing(TRUE);
  $node->skip_action_generation = TRUE;
  $node->skip_geocoding_enqueue = TRUE;
  $node->skip_recompute_enqueue = TRUE;
  $node->save();
  $remember($node);
  $membership = \Drupal::service('labdoo_team.membership.manager');
  if (!$membership->isAllowed($membership->getTeamIdFromNode($node), new AnonymousUserSession())) {
    throw new RuntimeException('This fixture must permit public team comments.');
  }
  if ($node->getFieldDefinition('title')->isTranslatable() || $node->getFieldDefinition('body')->isTranslatable()) {
    throw new RuntimeException('Review this shared-field fixture after a translation configuration change.');
  }

  // These mappings contain only synthetic values and never query a V2 source.
  $configure = static function (DestinationRepository|UserDestinationRepository $repository, array $fields): void {
    $mapping = [];
    foreach ($fields as $field) {
      $mapping[$field] = new MappingModel([], new FieldModel('', '', $field));
    }
    $class = $repository instanceof UserDestinationRepository ? UserDestinationRepository::class : DestinationRepository::class;
    (new ReflectionProperty($class, 'mapping'))->setValue($repository, $mapping);
    (new ReflectionProperty($class, 'dryRun'))->setValue($repository, FALSE);
  };
  $nodes = \Drupal::service('labdoo_migrate.destination_content.repository.node');
  $configure($nodes, ['title', 'body']);
  $updateNode = new ReflectionMethod($nodes, 'updateEntity');
  $englishTitle = 'E2E mapped content ' . $token;
  $spanishTitle = 'E2E contenido migrado ' . $token;
  $englishBody = 'Isolated mapped English content ' . $token;
  $spanishBody = 'Contenido aislado en español ' . $token;
  if (!$updateNode->invoke($nodes, ['title' => $englishTitle, 'body' => $englishBody], $node, 'en')) {
    throw new RuntimeException('Node destination mapping did not save.');
  }
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  $firstSave = Node::load($node->id());
  if ($firstSave->label() !== $englishTitle || $firstSave->get('body')->value !== $englishBody) {
    throw new RuntimeException('The first mapped values were not persisted.');
  }
  $translations = \Drupal::service('labdoo_migrate.destination_content.translation_repository');
  $translation = $translations->getEntityTranslation($node, 'es');
  if (!$updateNode->invoke($nodes, ['title' => $spanishTitle, 'body' => $spanishBody], $translation, 'en')) {
    throw new RuntimeException('Translated destination mapping did not save.');
  }
  if ($translations->getEntityTranslation($node, 'es')->id() !== $node->id()) {
    throw new RuntimeException('A repeated translation lookup created a different entity.');
  }

  $comment = Comment::create([
    'comment_type' => $node->getFieldDefinition('field_team_comments')->getSetting('comment_type'),
    'entity_type' => 'node', 'entity_id' => $node->id(), 'field_name' => 'field_team_comments',
    'uid' => $fixtures['accounts']['admin']['id'], 'status' => 1,
    'subject' => 'E2E mapped comment ' . $token,
    'comment_body' => ['value' => 'Initial isolated comment', 'format' => 'full_html'],
  ]);
  $comment->setSyncing(TRUE);
  $comments = \Drupal::service('labdoo_migrate.destination_content.repository.comment');
  if (!(new ReflectionMethod($comments, 'saveEntity'))->invoke($comments, $comment)) {
    throw new RuntimeException('Comment destination did not save.');
  }
  $remember($comment);
  $configure($comments, ['comment_body']);
  $commentText = 'Mapped comment body ' . $token;
  if (!(new ReflectionMethod($comments, 'updateEntity'))->invoke($comments, ['comment_body' => $commentText], $comment, 'en')) {
    throw new RuntimeException('Comment field mapping did not save.');
  }
  $privateComment = $comment->createDuplicate();
  $privateComment->set('status', 0);
  $privateComment->setSyncing(TRUE);
  if (!(new ReflectionMethod($comments, 'saveEntity'))->invoke($comments, $privateComment)) {
    throw new RuntimeException('The unpublished comment destination did not save.');
  }
  $remember($privateComment);
  $privateCommentText = 'Mapped unpublished comment body ' . $token;
  if (!(new ReflectionMethod($comments, 'updateEntity'))->invoke($comments, ['comment_body' => $privateCommentText], $privateComment, 'en')) {
    throw new RuntimeException('Unpublished comment field mapping did not save.');
  }

  $user = User::create(['name' => 'quality-' . $token, 'mail' => $token . '@example.invalid', 'status' => 1]);
  $user->setSyncing(TRUE);
  $users = \Drupal::service('labdoo_migrate.destination_content.repository.user');
  if (!(new ReflectionMethod($users, 'saveEntity'))->invoke($users, $user)) {
    throw new RuntimeException('User destination did not save.');
  }
  $remember($user);
  $configure($users, ['name']);
  $userName = 'quality-mapped-' . $token;
  if (!(new ReflectionMethod($users, 'updateEntity'))->invoke($users, ['name' => $userName], $user)) {
    throw new RuntimeException('User field mapping did not save.');
  }
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  $reloaded = Node::load($node->id());
  // This bundle shares title/body across languages. The last mapped values
  // must remain shared after a new translation is saved and loaded again.
  foreach ([$reloaded, $reloaded->getTranslation('es')] as $variant) {
    if ($variant->label() !== $spanishTitle || $variant->get('body')->value !== $spanishBody) {
      throw new RuntimeException('Mapped shared fields were not preserved across entity variants.');
    }
  }
  echo 'MIGRATION_JSON:' . json_encode([
    'node' => $node->id(), 'title' => $spanishTitle, 'body' => $spanishBody,
    'shared_fields' => TRUE,
    'comment_body' => $commentText, 'private_comment_body' => $privateCommentText,
    'user' => $user->id(), 'user_name' => $userName,
  ], JSON_THROW_ON_ERROR);
}
finally {
  foreach ($previous as $key => $value) {
    $value === NULL ? $state->delete($key) : $state->set($key, $value);
  }
}
