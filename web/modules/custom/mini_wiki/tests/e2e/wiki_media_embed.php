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

function renderedEmbed(MediaInterface $media, string $format): string {
  $markup = sprintf(
    '<drupal-media data-entity-type="media" data-entity-uuid="%s"></drupal-media>',
    $media->uuid()
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
}

$honeypot = \Drupal::config('honeypot.settings')->get('unprotected_forms') ?? [];
if (!in_array('media_library_add_form_oembed', $honeypot, TRUE)) {
  throw new RuntimeException('The remote video media form remains protected by Honeypot.');
}

echo "Embedded remote video, image, and media form checks passed.\n";
