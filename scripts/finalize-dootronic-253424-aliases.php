<?php

/**
 * Makes the two affected public URLs point to their verified V2 devices.
 *
 * Run with drush scr. Defaults to a dry run.
 */

use Drupal\Core\Database\Database;

$db = Database::getConnection();
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$alias = $aliasStorage->load(666799);
$corrected = $aliasStorage->load(679318);
$redirectRow = $db->select('redirect', 'r')
  ->fields('r')
  ->condition('r.redirect_source__path', 'content/000051805')
  ->execute()->fetchAssoc();

if (!$alias || $alias->getPath() !== '/node/205778'
  || !in_array($alias->getAlias(), ['/content/000051805-0', '/content/000051805'], TRUE)
  || !$corrected || $corrected->getPath() !== '/node/253424'
  || $corrected->getAlias() !== '/content/000063348'
  || ($redirectRow && $redirectRow['redirect_redirect__uri'] !== 'internal:/node/253424')) {
  throw new RuntimeException('Aliases or redirect differ from the expected post-repair state.');
}

if ($alias->getAlias() === '/content/000051805' && !$redirectRow) {
  echo "Canonical aliases are already correct.\n";
  return;
}

$collision = $db->select('path_alias', 'p')
  ->condition('p.alias', '/content/000051805')
  ->condition('p.id', 666799, '<>')
  ->countQuery()->execute()->fetchField();
if ($collision) {
  throw new RuntimeException('The canonical alias is already in use.');
}

echo "Will assign /content/000051805 to node 205778 and remove any redirect to node 253424.\n";
if (getenv('LABDOO_APPLY_REPAIR') !== '1') {
  echo "Dry run: no changes made.\n";
  return;
}

$backup = '/tmp/labdoo-dootronic-aliases-before-' . gmdate('YmdHis') . '.json';
file_put_contents($backup, json_encode([
  'alias' => $alias->toArray(),
  'redirect' => $redirectRow,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod($backup, 0600);
echo "Backup: {$backup}\n";

if ($alias->getAlias() !== '/content/000051805') {
  $alias->set('alias', '/content/000051805');
  $alias->save();
}
if ($redirectRow) {
  $redirect = \Drupal::entityTypeManager()->getStorage('redirect')->load($redirectRow['rid']);
  $redirect?->delete();
}
echo "Canonical aliases restored.\n";
