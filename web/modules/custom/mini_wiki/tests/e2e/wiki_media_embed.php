<?php

/**
 * Runtime smoke test: drush php:script web/modules/custom/mini_wiki/tests/e2e/wiki_media_embed.php
 *
 * Run against an installed site containing at least one published remote video
 * and one published image media item. No entities are changed.
 */

use Drupal\media\MediaInterface;

function testMedia(string $bundle): MediaInterface {
  $storage = \Drupal::entityTypeManager()->getStorage('media');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('bundle', $bundle)
    ->condition('status', 1)
    ->range(0, 1)
    ->execute();
  if (!$ids) {
    throw new RuntimeException("No published $bundle media item is available for this test.");
  }
  return $storage->load(reset($ids));
}

function renderedEmbed(MediaInterface $media, string $format, ?string $view_mode = NULL): string {
  $view_mode_attribute = $view_mode ? sprintf(' data-view-mode="%s"', $view_mode) : '';
  $markup = sprintf(
    '<drupal-media data-entity-type="media" data-entity-uuid="%s"%s></drupal-media>',
    $media->uuid(),
    $view_mode_attribute,
  );
  return (string) check_markup($markup, $format);
}

$video = testMedia('remote_video');
$image = testMedia('image');

foreach (['basic_html', 'full_html'] as $format) {
  $video_html = renderedEmbed($video, $format);
  if (!str_contains($video_html, '<iframe')) {
    throw new RuntimeException("$format did not render remote video as a playable iframe.");
  }

  $image_html = renderedEmbed($image, $format);
  if (!str_contains($image_html, '<img')) {
    throw new RuntimeException("$format did not retain the embedded image.");
  }

  $reduced_image_html = renderedEmbed($image, $format, 'wiki_medium');
  if (!str_contains($reduced_image_html, '/styles/wiki_medium/')) {
    throw new RuntimeException("$format did not render the reduced wiki image style.");
  }

  $legacy_html = (string) check_markup('[video: https://www.youtube.com/watch?v=h4UAOvLoAbc]', $format);
  if (!str_contains($legacy_html, 'https://www.youtube-nocookie.com/embed/h4UAOvLoAbc')) {
    throw new RuntimeException("$format did not render a migrated video token.");
  }
}

$team_post = \Drupal::entityTypeManager()->getStorage('node')->load(124714);
if ($team_post && $team_post->hasField('body') && !$team_post->get('body')->isEmpty()) {
  $body = $team_post->get('body')->first();
  $team_html = (string) check_markup($body->value, $body->format);
  if (substr_count($team_html, '<iframe') < 2) {
    throw new RuntimeException('The migrated anniversary team post still has unplayable videos.');
  }
}

$honeypot = \Drupal::config('honeypot.settings')->get('unprotected_forms') ?? [];
if (!in_array('media_library_add_form_oembed', $honeypot, TRUE)) {
  throw new RuntimeException('The remote video media form remains protected by Honeypot.');
}

$account_proxy = \Drupal::currentUser();
$original_account = $account_proxy->getAccount();
$wiki_writer = \Drupal\user\Entity\User::create([
  'name' => 'wiki-writer-format-smoke-test',
  'roles' => ['wiki_writer'],
]);
$account_proxy->setAccount($wiki_writer);
$wiki_page = \Drupal\mini_wiki\Entity\MiniWikiPage::create(['label' => 'Format smoke test']);
$wiki_form = \Drupal::service('entity.form_builder')->getForm($wiki_page, 'add');
if (($wiki_form['body']['widget'][0]['#format'] ?? NULL) !== 'basic_html') {
  throw new RuntimeException('Wiki writers without Full HTML access must default to Basic HTML.');
}
$account_proxy->setAccount($original_account);

if ($account_proxy->hasPermission('use text format full_html')) {
  $wiki_page = \Drupal\mini_wiki\Entity\MiniWikiPage::create(['label' => 'Format smoke test']);
  $wiki_form = \Drupal::service('entity.form_builder')->getForm($wiki_page, 'add');
  if (($wiki_form['body']['widget'][0]['#format'] ?? NULL) !== 'full_html') {
    throw new RuntimeException('Users with Full HTML access should default to Full HTML on new wiki pages.');
  }
}

echo "Embedded video, resized image, text-format defaults, and media form checks passed.\n";
