<?php

declare(strict_types=1);

use Drupal\labdoo_e2e\HttpGuard;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/tests/e2e/support/labdoo_e2e/src/HttpGuard.php';

$next_calls = 0;
$next = static function () use (&$next_calls): FulfilledPromise {
  $next_calls++;
  return new FulfilledPromise(new Response(200, [], 'local handler'));
};
$middleware = (new HttpGuard())();
$handler = $middleware($next);

$providers = $handler(new Request('GET', 'https://oembed.com/providers.json'), [])->wait();
if ($providers->getStatusCode() !== 200 || !str_contains((string) $providers->getBody(), 'YouTube')) {
  throw new RuntimeException('The deterministic oEmbed provider fixture was not returned.');
}

$youtube = $handler(new Request('GET', 'https://www.youtube.com/oembed?url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3Dh4UAOvLoAbc'), [])->wait();
if ($youtube->getStatusCode() !== 200 || !str_contains((string) $youtube->getBody(), 'h4UAOvLoAbc')) {
  throw new RuntimeException('The deterministic YouTube fixture was not returned.');
}

$video = $handler(new Request('GET', 'https://www.youtube.com/oembed?url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fe2e_title%3DGuard+video'), [])->wait();
if ($video->getStatusCode() !== 200 || !str_contains((string) $video->getBody(), 'Guard video')) {
  throw new RuntimeException('The controlled remote-video fixture was not returned.');
}

$blocked = FALSE;
try {
  $handler(new Request('GET', 'https://example.org/'), [])->wait();
}
catch (ConnectException) {
  $blocked = TRUE;
}
if (!$blocked) {
  throw new RuntimeException('External HTTP must be rejected by the isolated test guard.');
}

$handler(new Request('GET', 'http://127.0.0.1:18411/'), [])->wait();
if ($next_calls !== 1) {
  throw new RuntimeException('Only the loopback request should reach the next HTTP handler.');
}

echo "PASS: deterministic oEmbed fixtures, loopback forwarding and external HTTP rejection.\n";
