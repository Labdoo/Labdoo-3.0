<?php

require __DIR__ . '/harden.php';

use Drupal\Core\Site\Settings;

$file = Settings::get('labdoo_e2e_state') . '/blocked-video-fixture.json';
$storage = \Drupal::entityTypeManager()->getStorage('mini_wiki_page');
if (getenv('LABDOO_PROGRAM_TEST_OP') === 'cleanup') {
  $fixture = json_decode(file_get_contents($file), TRUE);
  $storage->load($fixture['id'])?->delete();
  if (!empty($fixture['created_media'])) {\Drupal::entityTypeManager()->getStorage('media')->load($fixture['created_media'])?->delete();}
  echo 'PROGRAM_JSON:' . json_encode(['cleaned' => TRUE]);
  return;
}
$media_storage = \Drupal::entityTypeManager()->getStorage('media');
$ids = $media_storage->getQuery()->accessCheck(FALSE)->condition('bundle', 'remote_video')->condition('status', 1)->range(0, 1)->execute();
if (!$ids) {
  $media = $media_storage->create(['bundle' => 'remote_video', 'name' => 'E2E video fixture', 'status' => 1, 'uid' => 1, 'field_media_oembed_video' => 'https://www.youtube.com/watch?v=h4UAOvLoAbc']);
  $media->save();
  $created_media = (int) $media->id();
}
else {
  $media = $media_storage->load(reset($ids));
}
$wiki = $storage->create([
  'label' => 'E2E responsive video sizing',
  'status' => 1,
  'uid' => 1,
  'body' => ['value' => '<drupal-media data-entity-type="media" data-entity-uuid="' . $media->uuid() . '"></drupal-media><p>[video: https://www.youtube.com/watch?v=h4UAOvLoAbc]</p>', 'format' => 'full_html'],
]);
$wiki->save();
$fixture = ['id' => (int) $wiki->id(), 'path' => $wiki->toUrl()->toString(), 'created_media' => $created_media ?? NULL];
file_put_contents($file, json_encode($fixture));
echo 'PROGRAM_JSON:' . json_encode($fixture);
