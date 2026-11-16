<?php

namespace Drupal\labdoo_e2e;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;

final class HttpGuard {
  public function __invoke(): callable {
    return static function (callable $handler): callable {
      return static function (RequestInterface $request, array $options) use ($handler) {
      // Controlled oEmbed fixture for the remote-video creation workflow.
      if ($request->getMethod() === 'GET' && $request->getUri()->getHost() === 'www.youtube.com' && $request->getUri()->getPath() === '/oembed') {
        parse_str($request->getUri()->getQuery(), $query);
        parse_str(parse_url($query['url'] ?? '', PHP_URL_QUERY) ?? '', $video);
        $payload = ['version' => '1.0', 'type' => 'video', 'title' => $video['e2e_title'] ?? 'E2E remote video', 'author_name' => 'E2E', 'provider_name' => 'YouTube', 'provider_url' => 'https://www.youtube.com/', 'width' => 320, 'height' => 180, 'html' => '<iframe src="https://www.youtube.com/embed/E2Etest1234"></iframe>'];
        return new \GuzzleHttp\Promise\FulfilledPromise(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($payload)));
      }
      if (!in_array($request->getUri()->getHost(), ['localhost', '127.0.0.1', '::1'], TRUE)) {
        return new RejectedPromise(new ConnectException('External HTTP is disabled in the isolated E2E site.', $request));
      }
      return $handler($request, $options);
      };
    };
  }
}
