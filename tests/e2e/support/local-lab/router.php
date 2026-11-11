<?php

$root = getenv('LABDOO_E2E_WEBROOT');
if (!$root || !is_file($root . '/index.php')) { throw new RuntimeException('Missing E2E web root'); }
chdir($root);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_contains($path, '..') || preg_match('@/(?:\.[^/]+|settings[^/]*\.php)@', $path)) {
  http_response_code(404);
  exit;
}
// PHP's development server ignores Drupal's .htaccess compression and expiry.
// Match the Apache transport for compressible assets on the actual local URL.
if (is_file($root . $path) && !str_ends_with($path, '.php')) {
  $types = [
    'css' => 'text/css',
    'js' => 'application/javascript',
    'json' => 'application/json',
    'map' => 'application/json',
    'svg' => 'image/svg+xml',
  ];
  $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  if (!isset($types[$extension])) { return FALSE; }
  $file = $root . $path;
  $etag = 'W/"' . hash_file('sha256', $file) . '"';
  header('Content-Type: ' . $types[$extension]);
  header('Cache-Control: public, max-age=31536000');
  header('ETag: ' . $etag);
  header('Vary: Accept-Encoding');
  if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
  }
  ob_start('ob_gzhandler');
  readfile($file);
  exit;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
// Use Drupal's normal front-controller sequence, enabling compression after
// bootstrap so its response preparation cannot discard the transport buffer.
$autoloader = require_once $root . '/autoload.php';
$kernel = new \Drupal\Core\DrupalKernel('prod', $autoloader);
$request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $kernel->handle($request);
$type = $response->headers->get('Content-Type', '');
if (preg_match('@^(text/|application/(json|javascript)|image/svg\+xml)@', $type)
    && !$response->headers->has('Content-Encoding')) {
  $response->headers->remove('Content-Length');
  ob_start('ob_gzhandler');
}
$response->send();
$kernel->terminate($request, $response);
