<?php

declare(strict_types=1);

namespace Drupal\mini_wiki\Plugin\Filter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;

/**
 * Displays video tokens retained in content migrated from Drupal 7.
 */
#[Filter(
  id: 'mini_wiki_legacy_video',
  title: new TranslatableMarkup('Display legacy YouTube video tokens'),
  description: new TranslatableMarkup('Converts [video: YouTube URL] into a playable video.'),
  type: FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
  weight: -1,
)]
final class LegacyVideoFilter extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $embedded = FALSE;
    $processed = preg_replace_callback('~\[video:\s*([^\]]+)\]~i', static function (array $matches) use (&$embedded): string {
      $id = self::videoIdFromUrl($matches[1]);
      if ($id === NULL) {
        return $matches[0];
      }

      $embedded = TRUE;
      return '<div class="labdoo-video-embed"><iframe src="https://www.youtube-nocookie.com/embed/' . $id . '" title="YouTube video" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>';
    }, (string) $text);

    $result = new FilterProcessResult($processed ?? (string) $text);
    if ($embedded) {
      $result->setAttachments(['library' => ['mini_wiki/legacy_video']]);
    }
    return $result;
  }

  /**
   * Extracts a safe YouTube ID from legacy video URLs.
   */
  public static function videoIdFromUrl(string $url): ?string {
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], TRUE)
      || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
      return NULL;
    }

    $host = preg_replace('/^(www\.)+/', '', strtolower(rtrim($parts['host'], '.')));
    $path = $parts['path'] ?? '';
    $id = NULL;

    if ($host === 'youtu.be' && preg_match('~^/([A-Za-z0-9_-]{11})/?$~', $path, $matches)) {
      $id = $matches[1];
    }
    elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], TRUE)) {
      if ($path === '/watch') {
        parse_str($parts['query'] ?? '', $query);
        $id = $query['v'] ?? NULL;
      }
      elseif (preg_match('~^/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})/?$~', $path, $matches)) {
        $id = $matches[1];
      }
    }

    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : NULL;
  }

}
