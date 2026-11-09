<?php

namespace Drupal\labdoo_e2e;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;

final class HttpGuard {
  public function __invoke(): callable {
    return static function (callable $handler): callable {
      return static function (RequestInterface $request, array $options) use ($handler) {
      // Deterministic oEmbed fixtures; never contact a remote provider in E2E.
      $host = $request->getUri()->getHost();
      if ($host === 'oembed.com' && $request->getUri()->getPath() === '/providers.json') {
        $data = [['provider_name' => 'YouTube', 'provider_url' => 'https://www.youtube.com/', 'endpoints' => [['schemes' => ['https://www.youtube.com/watch*'], 'url' => 'https://www.youtube.com/oembed', 'discovery' => TRUE]]]];
        return new \GuzzleHttp\Promise\FulfilledPromise(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
      }
      if ($host === 'www.youtube.com' && $request->getUri()->getPath() === '/oembed' && str_contains(urldecode($request->getUri()->getQuery()), 'h4UAOvLoAbc')) {
        $data = ['version' => '1.0', 'type' => 'video', 'provider_name' => 'YouTube', 'title' => 'E2E video fixture', 'width' => 300, 'height' => 150, 'html' => '<iframe src="https://www.youtube-nocookie.com/embed/h4UAOvLoAbc" width="300" height="150"></iframe>'];
        return new \GuzzleHttp\Promise\FulfilledPromise(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
      }
      if (!in_array($request->getUri()->getHost(), ['localhost', '127.0.0.1', '::1'], TRUE)) {
        return new RejectedPromise(new ConnectException('External HTTP is disabled in the isolated E2E site.', $request));
      }
      return $handler($request, $options);
      };
    };
  }
}
