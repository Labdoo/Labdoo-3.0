<?php

namespace Drupal\Tests\mini_wiki\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

use Drupal\Tests\UnitTestCase;
use Drupal\mini_wiki\Plugin\Filter\LegacyVideoFilter;

/**
 * Tests safe conversion of legacy video tokens.
 */
#[CoversClass(LegacyVideoFilter::class)]
#[Group('mini_wiki')]
class LegacyVideoFilterTest extends UnitTestCase {

  /**
   * Validates video hosts and rejects malformed or unsafe URLs.
   */
  #[DataProvider('urlProvider')]
  public function testVideoIdFromUrl(string $url, ?string $expected): void {
    $this->assertSame($expected, LegacyVideoFilter::videoIdFromUrl($url));
  }

  /**
   * Provides accepted and rejected legacy URLs.
   */
  public static function urlProvider(): array {
    return [
      'YouTube watch' => ['https://www.youtube.com/watch?v=h4UAOvLoAbc', 'h4UAOvLoAbc'],
      'privacy host' => ['https://www.youtube-nocookie.com/embed/S2WaQBPB1OE', 'S2WaQBPB1OE'],
      'old repeated www' => ['https://www.www.youtube-nocookie.com/watch?v=KMLAoeq0T54', 'KMLAoeq0T54'],
      'short link' => ['https://youtu.be/45T3L-g2yqg?list=old', '45T3L-g2yqg'],
      'escaped query' => ['https://www.youtube.com/watch?v=h8Qdp6gkQP4&amp;yt:cc=on', 'h8Qdp6gkQP4'],
      'lookalike host' => ['https://youtube.com.evil.example/watch?v=h4UAOvLoAbc', NULL],
      'script URL' => ['javascript:alert(1)', NULL],
      'bad video ID' => ['https://www.youtube.com/watch?v=invalid', NULL],
    ];
  }

  /**
   * Transforms valid video tokens and preserves invalid input.
   */
  public function testTransformsOnlyValidTokens(): void {
    $filter = new LegacyVideoFilter([], 'mini_wiki_legacy_video', ['provider' => 'mini_wiki']);
    $result = $filter->process('[video: https://youtu.be/h4UAOvLoAbc] [video: javascript:alert(1)]', 'en');
    $html = $result->getProcessedText();

    $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/h4UAOvLoAbc', $html);
    $this->assertStringContainsString('allowfullscreen', $html);
    $this->assertStringContainsString('[video: javascript:alert(1)]', $html);
    $this->assertSame(['mini_wiki/legacy_video'], $result->getAttachments()['library']);
  }

}
