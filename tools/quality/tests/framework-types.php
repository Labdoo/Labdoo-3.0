<?php

/**
 * @file
 * Checks that standalone test doubles cannot replace real framework types.
 */

declare(strict_types=1);

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_common\Service\Repository\UserRepository;
use Drupal\labdoo_common\Service\Helper\LinkHelper;
use Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface;
use Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface;
use Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepository;
use Drupal\labdoo_migrate\Services\DestinationContent\UserDestinationRepository;
use Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepositoryInterface;
use function PHPStan\Testing\assertType;

/**
 * Exercises real core and custom APIs during application type discovery.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   A Drupal entity with the core identity contract.
 * @param \Drupal\node\NodeInterface $node
 *   A node with the documented content API.
 * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $repository
 *   The real repository, rather than a standalone test double.
 */
function labdoo_quality_framework_types(EntityInterface $entity, NodeInterface $node, CommonRepository $repository): void {
  $entity->id();
  $entity->bundle();
  $entity->label();
  $node->getType();
  $node->getTitle();
  $repository->formatNumber(1234);
}

/**
 * Checks repository result types against their actual storage implementations.
 *
 * Node loaders may return NULL; collection loaders return arrays of nodes.
 * The user loader must expose the user API rather than just entity identity.
 * This fixture is analysed statically and never bootstraps a Drupal site.
 *
 * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $common
 *   The shared node repository.
 * @param \Drupal\labdoo_common\Service\Repository\UserRepository $users
 *   The current-user repository.
 * @param \Drupal\labdoo_common\Service\Helper\LinkHelper $links
 *   The link helper's node loader.
 * @param \Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface $trips
 *   The trip repository.
 * @param \Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface $hubs
 *   The hub repository.
 * @param \Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface $schools
 *   The school repository.
 */
function labdoo_quality_repository_types(
  CommonRepository $common,
  UserRepository $users,
  LinkHelper $links,
  DootripRepositoryInterface $trips,
  HubRepositoryInterface $hubs,
  EdooVillageRepositoryInterface $schools,
): void {
  assertType('Drupal\node\NodeInterface|null', $common->loadEntity(1));
  assertType('Drupal\node\NodeInterface|null', $common->getStory(1));
  assertType('Drupal\node\NodeInterface|null', $links->loadEntity(1));
  assertType('Drupal\node\NodeInterface|null', $trips->load(1));
  assertType('array<Drupal\node\NodeInterface>', $trips->loadByProperties(['type' => 'dootrip']));
  assertType('Drupal\node\NodeInterface|null', $hubs->load(1));
  assertType('Drupal\node\NodeInterface|null', $schools->load(1));
  assertType('Drupal\user\UserInterface|null', $users->getCurrentUser());
  assertType('float', $common->getCo2Saved(1));
}

/**
 * Checks observed nullable relation contracts and real dependent APIs.
 *
 * @param \Drupal\node\NodeInterface $node
 *   A node whose owner reference may no longer resolve.
 * @param \Drupal\webform\WebformSubmissionInterface $submission
 *   A submission whose source form may no longer resolve.
 */
function labdoo_quality_nullable_relations(NodeInterface $node, WebformSubmissionInterface $submission): void {
  assertType('Drupal\user\UserInterface|null', $node->getOwner());
  assertType('Drupal\webform\WebformInterface|null', $submission->getWebform());
  $node->getOwner()?->getEmail();
  $submission->getWebform()?->id();
}

/**
 * Checks destination collection types and subtype-preserving translations.
 */
function labdoo_quality_migration_destinations(DestinationRepository $nodes, UserDestinationRepository $users, TranslationRepositoryInterface $translations, NodeInterface $node): void {
  assertType('array<int, Drupal\Core\Entity\ContentEntityInterface>', $nodes->getEntities([]));
  assertType('array<int, Drupal\user\UserInterface>', $users->getEntities([]));
  assertType('Drupal\node\NodeInterface', $translations->getEntityTranslation($node, 'es'));
}

/**
 * Keeps nullable dependency readers and fluent SELECT methods visible.
 */
function labdoo_quality_dependency_readers(
  \Drupal\Component\Gettext\PoStreamReader $reader,
  \Drupal\language\Config\LanguageConfigFactoryOverrideInterface $overrides,
  \Drupal\Core\Database\Query\SelectInterface $query,
): void {
  assertType('Drupal\Component\Gettext\PoItem|null', $reader->readItem());
  assertType('Drupal\Core\Language\LanguageInterface|null', $overrides->getLanguage());
  assertType('Drupal\Core\Database\Query\SelectInterface', $query->forUpdate());
  $query->forUpdate()->execute();
}
