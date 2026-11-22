<?php

/**
 * @file
 * Checks the generated print-label markup.
 *
 * Set LABDOO_AUTOLOAD to the Composer autoloader before running this file.
 */

require getenv('LABDOO_AUTOLOAD') ?: dirname(__DIR__, 5) . '/vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$loader = new FilesystemLoader(dirname(__DIR__) . '/templates/page');
$twig = new Environment($loader);
$html = $twig->render('page--dootronics-print-labels.html.twig', [
  'page' => [
    'content' => 'LABEL_TEST_MARKER',
    'header' => 'HEADER_TEST_MARKER',
    'sidebar' => 'SIDEBAR_TEST_MARKER',
    'footer' => 'FOOTER_TEST_MARKER',
  ],
]);

if (!str_contains($html, 'LABEL_TEST_MARKER') || preg_match('/(HEADER|SIDEBAR|FOOTER)_TEST_MARKER/', $html)) {
  fwrite(STDERR, "Print label page renders unrelated content.\n");
  exit(1);
}

echo "Print label page renders labels only.\n";
