<?php

$root = getenv('LABDOO_E2E_WEBROOT');
if (!$root || !is_file($root . '/index.php')) { throw new RuntimeException('Missing E2E web root'); }
chdir($root);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_contains($path, '..') || preg_match('@/(?:\.[^/]+|settings[^/]*\.php)@', $path)) {
  http_response_code(404);
  exit;
}
if (is_file($root . $path) && !str_ends_with($path, '.php')) { return FALSE; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
