<?php

namespace Drupal\labdoo_wiki\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;

/**
 * Controller for user broken links page.
 */
class UserBrokenLinksController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a UserBrokenLinksController object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database')
    );
  }

  /**
   * Custom access check.
   */
  public function access(UserInterface $user, AccountInterface $account) {
    // Permitir si es el propio usuario o tiene permisos de administración
    return AccessResult::allowedIf(
      $account->id() == $user->id() ||
      $account->hasPermission('administer linkchecker')
    );
  }

  /**
   * Displays broken links for user's wiki pages.
   */
  public function brokenLinks(UserInterface $user): array {
    $wiki_pages = $this->entityTypeManager
      ->getStorage('mini_wiki_page')
      ->loadByProperties(['uid' => $user->id()]);

    if (empty($wiki_pages)) {
      return [
        '#markup' => $this->t('You have not created any wiki pages yet.'),
      ];
    }

    $entity_ids = array_keys($wiki_pages);

    $query = $this->entityTypeManager
      ->getStorage('linkcheckerlink')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('parent_entity_type_id', 'mini_wiki_page')
      ->condition('parent_entity_id', $entity_ids, 'IN')
      ->condition('status', 0) // 0 = broken, 1 = ok
      ->sort('last_check', 'DESC');

    $link_ids = $query->execute();

    if (empty($link_ids)) {
      return [
        '#markup' => $this->t('Great! You have no broken links in your wiki pages.'),
        '#prefix' => '<div class="messages messages--status">',
        '#suffix' => '</div>',
      ];
    }

    $links = $this->entityTypeManager
      ->getStorage('linkcheckerlink')
      ->loadMultiple($link_ids);

    $rows = [];
    foreach ($links as $link) {
      $entity_id = $link->get('parent_entity_id')->value;

      if (isset($wiki_pages[$entity_id])) {
        $wiki_page = $wiki_pages[$entity_id];
        $last_check = $link->get('last_check')->value;

        $rows[] = [
          'wiki_page' => [
            'data' => [
              '#type' => 'link',
              '#title' => $wiki_page->label(),
              '#url' => $wiki_page->toUrl(),
            ],
          ],
          'broken_link' => [
            'data' => [
              '#markup' => '<span class="broken-link">' . $link->getUrl() . '</span>',
            ],
          ],
          'status' => $this->t('HTTP @code', ['@code' => $link->getStatusCode() ?: 'N/A']),
          'error' => $link->getErrorMessage() ?: $this->t('No response'),
          'last_checked' => [
            'data' => $last_check ? \Drupal::service('date.formatter')->format($last_check, 'short') : $this->t('Never'),
          ],
        ];
      }
    }

    return [
      '#theme' => 'table',
      '#header' => [
        $this->t('Wiki Page'),
        $this->t('Broken Link'),
        $this->t('Status'),
        $this->t('Error'),
        $this->t('Last Checked'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No broken links found.'),
      '#attributes' => ['class' => ['user-broken-links-table']],
      '#attached' => [
        'library' => ['labdoo_wiki/broken_links_styling'],
      ],
    ];
  }

}
