<?php

/**
 * @file
 * Exercises the empty confirmation form without deleting clone content.
 */

declare(strict_types=1);

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\natiboo_migrate_data\Form\DeleteParagraphsForm;
use Drupal\natiboo_migrate_data\Service\ParagraphDelete;

require __DIR__ . '/assert-isolated.php';

$container = Drupal::getContainer();
$storage = Drupal::entityTypeManager()->getStorage('paragraph');
$before = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
$original = $container->get('natiboo_migrate_data.paragraph.delete');
$empty = new class extends ParagraphDelete {

  public function __construct() {}

  public function getParagraphCount(): int {
    return 0;
  }

  public function deleteAllParagraphs(bool $useBatch = TRUE): int {
    throw new RuntimeException('The empty-form regression must never delete paragraphs.');
  }

};
$container->set('natiboo_migrate_data.paragraph.delete', $empty);
try {
  try {
    Drupal::formBuilder()->getForm(DeleteParagraphsForm::class);
    throw new RuntimeException('The empty form did not enforce its redirect.');
  }
  catch (EnforcedResponseException $exception) {
    $response = $exception->getResponse();
    $location = parse_url($response->headers->get('Location'), PHP_URL_PATH);
    if ($response->getStatusCode() !== 302 || !str_ends_with($location, '/admin/config/natiboo-migrate/entities-list')) {
      throw new RuntimeException('The empty form returned an unexpected redirect.');
    }
  }
}
finally {
  $container->set('natiboo_migrate_data.paragraph.delete', $original);
}
$after = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
if ($before !== $after) {
  throw new RuntimeException('The empty-form regression changed existing paragraphs.');
}
echo 'EMPTY_FORM_JSON:' . json_encode([
  'status' => 302,
  'location' => $location,
  'unchanged_content' => TRUE,
], JSON_THROW_ON_ERROR);
