<?php

namespace Drupal\labdoo_e2e;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;

final class HttpGuard {
  public function __invoke(): callable {
    return static function (callable $handler): callable {
      return static function (RequestInterface $request, array $options) use ($handler) {
      if (!in_array($request->getUri()->getHost(), ['localhost', '127.0.0.1', '::1'], TRUE)) {
        return new RejectedPromise(new ConnectException('External HTTP is disabled in the isolated E2E site.', $request));
      }
      return $handler($request, $options);
      };
    };
  }
}
