<?php

require __DIR__ . '/harden.php';
umask(0077);
$variant = \Drupal\Core\Site\Settings::get('labdoo_e2e_variant');
$path = \Drupal\Core\Site\Settings::get('labdoo_e2e_state') . '/' . $variant . '-fixtures.json';
$data = is_file($path) ? json_decode(file_get_contents($path), TRUE) : ['accounts' => [], 'nodes' => [], 'wiki' => [], 'media' => []];
$persist = static function () use (&$data, $path) { file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); chmod($path, 0600); };
$userStorage = \Drupal::entityTypeManager()->getStorage('user');
foreach (['admin' => ['administrator'], 'basic' => [], 'laptop' => ['laptop_manager'], 'hub' => ['hub_manager'], 'school' => ['edoovillage_manager'], 'wiki' => ['wiki_writer'], 'team' => ['team_manager']] as $key => $roles) {
  $name = 'e2e_' . $key;
  $matches = $userStorage->loadByProperties(['name' => $name]);
  $account = $matches ? reset($matches) : $userStorage->create(['name' => $name, 'mail' => $name . '@example.invalid', 'status' => 1, 'roles' => $roles]);
  $password = $data['accounts'][$key]['password'] ?? bin2hex(random_bytes(12));
  $account->setPassword($password)->save();
  $data['accounts'][$key] = ['id' => (int) $account->id(), 'name' => $name, 'password' => $password];
  $persist();
}
$admin = $userStorage->load($data['accounts']['admin']['id']);
\Drupal::service('account_switcher')->switchTo($admin);
\Drupal::database()->truncate('flood')->execute();
$storage = \Drupal::entityTypeManager()->getStorage('node');
$prototypes = [];
$node = static function (string $bundle, string $key, array $values = []) use (&$data, &$prototypes, $storage, $admin, $persist) {
  if (isset($data['nodes'][$key]['id']) && ($existing = $storage->load($data['nodes'][$key]['id']))) { return $existing; }
  if (!array_key_exists($bundle, $prototypes)) {
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->condition('status', 1)->range(0, 1)->execute();
    $prototypes[$bundle] = $ids ? $storage->load(reset($ids)) : NULL;
  }
  $entity = $prototypes[$bundle] ? $prototypes[$bundle]->createDuplicate() : $storage->create(['type' => $bundle]);
  $entity->setTitle('E2E ' . $key)->setOwnerId($admin->id())->setPublished()->setCreatedTime(time());
  $entity->skip_geocoding_enqueue = TRUE;
  $entity->skip_recompute_enqueue = TRUE;
  foreach (['field_edoo_additional_editors', 'field_edoo_additional_followers', 'field_edoo_additional_notif_em', 'field_edoovillage_photo_album', 'field_dootrips', 'field_laptops'] as $field) {
    if ($entity->hasField($field)) { $entity->set($field, []); }
  }
  foreach ($values as $field => $value) { if ($entity->hasField($field)) { $entity->set($field, $value); } }
  $entity->save();
  $data['nodes'][$key] = ['id' => (int) $entity->id(), 'title' => $entity->label(), 'path' => '/en/node/' . $entity->id()];
  $persist();
  return $entity;
};
$location = ['value' => 'POINT (-3.7038 40.4168)'];
$hub = $node('hub', 'Hub Alpha', ['field_country' => 'ES', 'field_locations' => $location, 'field_hub_manager_s_' => [$admin->id()]]);
$otherHub = $node('hub', 'Hub Zulu', ['field_country' => 'ES', 'field_locations' => $location, 'field_hub_manager_s_' => [$admin->id()]]);
$school = $node('edoovillage', 'School', ['field_city' => 'E2E Madrid', 'field_country' => 'ES', 'field_location' => $location, 'field_hub' => [$hub->id()], 'field_project_manager_s_' => [$admin->id()], 'field_number_of_laptops_needed' => 5]);
$device = $node('dootronic', 'Laptop Main', ['field_model' => 'E2E Laptop Main', 'field_serial_number' => 'E2E-SERIAL-MAIN', 'field_country' => 'ES', 'field_city' => 'Madrid', 'field_locations' => $location, 'field_location' => $location, 'field_hub' => [$hub->id(), $otherHub->id()], 'field_manager' => $data['accounts']['laptop']['id'], 'field_dootronic_status' => 'S1', 'field_address' => ['country_code' => 'ES', 'locality' => 'Madrid', 'postal_code' => '28001', 'address_line1' => 'E2E Street 42']]);
$second = $node('dootronic', 'Laptop Second', ['field_model' => 'E2E Laptop Second', 'field_serial_number' => 'E2E-SERIAL-SECOND', 'field_country' => 'ES', 'field_locations' => $location, 'field_location' => $location, 'field_hub' => [$hub->id()], 'field_manager' => $data['accounts']['laptop']['id'], 'field_dootronic_status' => 'S4', 'field_edoovillage_destination' => $school->id()]);
$trip = $node('dootrip', 'Trip', ['field_origin_of_the_trip' => $location, 'field_destination_of_the_trip' => ['value' => 'POINT (2.1734 41.3851)'], 'field_locations' => $location, 'field_departure_date' => gmdate('Y-m-d\TH:i:s', time() + 86400 * 30), 'field_arrival_date' => gmdate('Y-m-d\TH:i:s', time() + 86400 * 31), 'field_dootrip_capacity' => 5, 'field_number_of_doojects_assigne' => 2, 'field_dootripper_s' => [$admin->id()], 'field_hub' => [$hub->id()], 'field_laptops' => [$device->id(), $second->id()]]);
$team = $node('team', 'Team', ['field_description' => ['value' => 'E2E team description', 'format' => 'full_html']]);
$post = $node('team_post', 'Team Post', ['field_team' => $team->id(), 'body' => ['value' => '<p>E2E searchable post text</p>', 'format' => 'full_html']]);
$wikiStorage = \Drupal::entityTypeManager()->getStorage('mini_wiki_page');
$wikiFields = \Drupal::service('entity_field.manager')->getFieldDefinitions('mini_wiki_page', 'mini_wiki_page');
$wiki = static function (string $key, ?int $parent, int $weight) use (&$data, $wikiStorage, $wikiFields, $admin, $persist) {
  if (isset($data['wiki'][$key]['id']) && ($existing = $wikiStorage->load($data['wiki'][$key]['id']))) { return $existing; }
  $values = ['label' => 'E2E ' . $key, 'langcode' => 'en', 'status' => 1, 'uid' => $admin->id(), 'body' => ['value' => '<h2>E2E Heading</h2><p>' . str_repeat('Readable E2E wiki text. ', 80) . '</p>', 'format' => 'full_html']];
  if ($parent) { $values['parent'] = $parent; }
  if (isset($wikiFields['weight'])) { $values['weight'] = $weight; }
  $entity = $wikiStorage->create($values); $entity->save();
  $data['wiki'][$key] = ['id' => (int) $entity->id(), 'title' => $entity->label(), 'path' => '/en/wiki-page/' . $entity->id()]; $persist();
  return $entity;
};
$book = $wiki('Book', NULL, 0);
$alpha = $wiki('Alpha', (int) $book->id(), 10);
$zulu = $wiki('Zulu', (int) $book->id(), 20);
$otherBook = $wiki('Other Book', NULL, 30);
if ($book->hasField('field_tags') && $book->get('field_tags')->isEmpty()) {
  $bundles = $book->get('field_tags')->getFieldDefinition()->getSetting('handler_settings')['target_bundles'];
  $vid = reset($bundles);
  $tags = [];
  foreach (['E2E Tag One', 'E2E Tag Two'] as $name) { $term = \Drupal\taxonomy\Entity\Term::create(['vid' => $vid, 'name' => $name]); $term->save(); $tags[] = $term->id(); }
  $book->set('field_tags', $tags)->save();
}
$fs = \Drupal::service('file_system');
$directory = 'public://e2e'; $fs->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
$filename = $fs->realpath($directory) . '/fixture.png';
if (!file_exists($filename)) { $image = imagecreatetruecolor(16, 16); imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200)); imagepng($image, $filename); }
$fileStorage = \Drupal::entityTypeManager()->getStorage('file');
$matches = $fileStorage->loadByProperties(['uri' => 'public://e2e/fixture.png']);
$file = $matches ? reset($matches) : $fileStorage->create(['uri' => 'public://e2e/fixture.png', 'filename' => 'fixture.png', 'filemime' => 'image/png', 'status' => 1, 'uid' => $admin->id()]);
$file->save();
$mediaStorage = \Drupal::entityTypeManager()->getStorage('media');
for ($i = 1; $i <= 53; ++$i) {
  if (isset($data['media'][$i - 1]) && $mediaStorage->load($data['media'][$i - 1])) { continue; }
  $media = $mediaStorage->create(['bundle' => 'image', 'name' => sprintf('E2E Photo %02d', $i), 'status' => 1, 'uid' => $admin->id(), 'field_media_image' => ['target_id' => $file->id(), 'alt' => sprintf('E2E Photo %02d', $i), 'width' => 16, 'height' => 16]]);
  $media->save(); $data['media'][$i - 1] = (int) $media->id(); $persist();
}
$gallery = $node('gallery', 'Gallery', ['field_parent' => $school->id(), 'field_images' => $data['media'], 'body' => ['value' => 'E2E gallery with 53 photographs.', 'format' => 'full_html']]);
$school->set('field_edoovillage_photo_album', $gallery->id())->save();
foreach (['Hub Story' => $hub, 'School Story' => $school] as $key => $parent) {
  if (!isset($data['nodes'][$key])) {
    $paragraph = \Drupal\paragraphs\Entity\Paragraph::create(['type' => 'story_section', 'field_story_heading' => 'E2E story heading', 'field_story_text' => ['value' => '<p>E2E story content.</p>', 'format' => 'full_html'], 'field_story_picture' => ['target_id' => $file->id(), 'alt' => 'E2E story picture']]);
    $paragraph->save();
    $node('labdoo_story', $key, ['field_parent' => $parent->id(), 'field_story_section' => [['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()]]]);
  }
}
$data['variant'] = $variant;
$data['ready'] = TRUE;
$persist();
echo 'Seeded E2E accounts, objects, wiki books, stories and 53 photos for ' . $variant . '.' . PHP_EOL;
