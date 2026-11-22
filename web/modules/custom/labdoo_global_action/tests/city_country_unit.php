<?php

/**
 * @file
 * Runs the five local geography contracts with the real Drupal unit framework.
 */

declare(strict_types=1);

use PHPUnit\TextUI\Application;

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
$qualityRoot = dirname(__DIR__, 5) . '/tools/quality';
require $qualityRoot . '/autoload.php';
$reportPath = tempnam(sys_get_temp_dir(), 'labdoo-city-unit-');
if ($reportPath === FALSE) {
  throw new RuntimeException('Unable to create the unit result file.');
}
try {
  $status = (new Application())->run([
    __FILE__, '--configuration', $qualityRoot . '/phpunit.xml',
    '--filter', 'CityCountryResolutionTest', '--no-progress',
    '--log-junit', $reportPath,
  ]);
  if ($status === 0) {
    $xml = new DOMDocument();
    if (!$xml->load($reportPath, LIBXML_NONET)) {
      throw new RuntimeException('Missing geography unit results.');
    }
    $suite = $xml->getElementsByTagName('testsuite')->item(0);
    if (!$suite instanceof DOMElement || (int) $suite->getAttribute('tests') !== 5) {
      throw new RuntimeException('Exactly five geography cases must execute.');
    }
    foreach (['failures', 'errors', 'skipped'] as $attribute) {
      if ((int) $suite->getAttribute($attribute) !== 0) {
        throw new RuntimeException('Every geography case must pass.');
      }
    }
    foreach ($xml->getElementsByTagName('testcase') as $case) {
      $name = $case->getAttribute('name');
      preg_match('/with data set "([^"]+)"/', $name, $match);
      echo 'PASS: ' . ($match[1] ?? $name) . PHP_EOL;
    }
  }
}
finally {
  unlink($reportPath);
}
exit($status);
