<?php

namespace Drupal\Tests\mini_wiki\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps rich text embeds playable without changing image thumbnails.
 *
 * @group mini_wiki
 */
class EmbeddedMediaConfigurationTest extends UnitTestCase {

  /**
   * Tests the media modes selected by rich text formats.
   */
  public function testEmbeddedMediaModes(): void {
    $config_path = dirname(__DIR__, 7) . '/config/sync/';

    foreach (['basic_html', 'full_html'] as $format) {
      $config = Yaml::parseFile($config_path . "filter.format.$format.yml");
      $settings = $config['filters']['media_embed']['settings'];
      $this->assertSame('embedded_content', $settings['default_view_mode']);
      $this->assertSame(['embedded_content' => 'embedded_content'], $settings['allowed_view_modes']);
      $this->assertTrue($config['filters']['mini_wiki_legacy_video']['status']);
    }

    $image = Yaml::parseFile($config_path . 'core.entity_view_display.media.image.embedded_content.yml');
    $remote_video = Yaml::parseFile($config_path . 'core.entity_view_display.media.remote_video.embedded_content.yml');
    $video = Yaml::parseFile($config_path . 'core.entity_view_display.media.video.embedded_content.yml');

    $this->assertSame('medium', $image['content']['thumbnail']['settings']['image_style']);
    $this->assertSame('oembed', $remote_video['content']['field_media_oembed_video']['type']);
    $this->assertSame('file_video', $video['content']['field_media_video_file']['type']);
    $this->assertTrue($video['content']['field_media_video_file']['settings']['controls']);
  }

  /**
   * Tests the media library form does not receive a conflicting honeypot field.
   */
  public function testRemoteVideoFormIsUnprotected(): void {
    $config_path = dirname(__DIR__, 7) . '/config/sync/';
    $honeypot = Yaml::parseFile($config_path . 'honeypot.settings.yml');

    $this->assertContains('media_library_add_form_oembed', $honeypot['unprotected_forms']);
  }

}
