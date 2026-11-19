<?php

declare(strict_types=1);

/**
 * @file
 * Guarded fixtures and dedicated queue runner for translation browser tests.
 */

require __DIR__ . '/assert-isolated.php';

use Drupal\Component\Gettext\PoItem;
use Drupal\Core\Site\Settings;
use Drupal\labdoo_common\Service\TranslationJobs;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

$state = Settings::get('labdoo_e2e_state');
$directory = $state . '/translation-ui-fixture';
$manifest = $state . '/translation-ui.json';
$operation = getenv('LABDOO_TRANSLATION_UI_OP') ?: 'inspect';
$id = getenv('LABDOO_TRANSLATION_UI_JOB') ?: '';
$context = 'labdoo-translation-ui-e2e';
$storage = \Drupal::service('locale.storage');
$fixtures = json_decode(file_get_contents($state . '/integration-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);

if ($operation === 'prepare') {
  if (is_file($manifest)) { throw new RuntimeException('Clean the existing translation test fixture first.'); }
  if (!is_dir($directory)) { mkdir($directory, 0700); }
  $binary = $directory . '/agent';
  file_put_contents($binary, <<<'AGENT'
#!/usr/bin/env php
<?php
$input = stream_get_contents(STDIN);
file_put_contents(__DIR__ . '/calls', "call\n", FILE_APPEND);
if (is_file(__DIR__ . '/fail')) { echo 'invalid JSON'; exit(0); }
$entries = json_decode(substr($input, strpos($input, "\n") + 1), TRUE, 512, JSON_THROW_ON_ERROR);
$response = [];
foreach ($entries as $entry) {
  $sources = (array) $entry['source'];
  $response[$entry['id']] = 'Traducción ' . $sources[min(count($sources) - 1, $entry['plural_index'])];
}
echo json_encode($response, JSON_UNESCAPED_UNICODE);
AGENT);
  chmod($binary, 0700);
  $lids = [];
  for ($i = 0; $i < 14; $i++) {
    $source = 'UI translation @name ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ' <strong>text</strong>';
    $lids[] = $storage->createString(['source' => $source, 'context' => $context])->save()->getId();
  }
  $lids[] = $storage->createString(['source' => 'One @count UI item' . PoItem::DELIMITER . 'Many @count UI items', 'context' => $context])->save()->getId();
  $role = Role::create(['id' => 'translation_ui_operator', 'label' => 'Translation UI operator']);
  $role->grantPermission(TranslationJobs::PERMISSION)->save();
  foreach (['basic', 'wiki'] as $kind) { $user = User::load($fixtures['accounts'][$kind]['id']); $user->addRole($role->id())->save(); }
  $data = ['context' => $context, 'lids' => $lids, 'profiles' => ['fixture' => ['label' => 'Simulated translator', 'adapter' => 'custom', 'binary' => $binary, 'models' => ['test-model']]], 'plural_before' => \Drupal::state()->get('locale.translation.formulae')];
  file_put_contents($manifest, json_encode($data, JSON_THROW_ON_ERROR));
  echo "fixture_ready\n";
}
elseif ($operation === 'seed') {
  $id = \Drupal::service('labdoo_common.translation_jobs')->create(User::load($fixtures['accounts']['basic']['id']), ['es'], 'fixture', 'test-model', \Drupal::service('uuid')->generate());
}
elseif (in_array($operation, ['step', 'drain', 'revoke-during-drain'], TRUE)) {
  $queue = \Drupal::queue(TranslationJobs::QUEUE);
  $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance(TranslationJobs::QUEUE);
  $count = 0;
  while (($item = $queue->claimItem(300)) && $count++ < 200) {
    $worker->processItem($item->data); $queue->deleteItem($item);
    if ($operation === 'revoke-during-drain' && $count === 1) {
      // A separate process invalidates persistent caches, but the queue runner
      // still has its previously loaded owner in Drupal's entity memory cache.
      $revoke = new \Symfony\Component\Process\Process(['python3', dirname(DRUPAL_ROOT) . '/tests/upgrade/lab.py', 'drush', 'php:script', __FILE__], dirname(DRUPAL_ROOT), ['LABDOO_TRANSLATION_UI_OP' => 'revoke']);
      $revoke->setTimeout(90)->mustRun();
    }
    if ($operation === 'step') { break; }
  }
  if ($count >= 200) { throw new RuntimeException('Unexpected queue loop.'); }
  echo 'queue_steps=' . $count . "\n";
}
elseif ($operation === 'fail') { file_put_contents($directory . '/fail', '1'); }
elseif ($operation === 'clear-failure') { if (is_file($directory . '/fail')) { unlink($directory . '/fail'); } }
elseif ($operation === 'duplicate') {
  \Drupal::queue(TranslationJobs::QUEUE)->createItem(['job_id' => $id]);
  \Drupal::queue(TranslationJobs::QUEUE)->createItem(['job_id' => '00000000-0000-0000-0000-000000000000']);
}
elseif ($operation === 'protect') {
  $data = json_decode(file_get_contents($manifest), TRUE, 512, JSON_THROW_ON_ERROR);
  $storage->createTranslation(['lid' => $data['lids'][0], 'language' => 'es', 'translation' => 'Manual translation @name <strong>text</strong>', 'customized' => LOCALE_CUSTOMIZED])->save();
}
elseif ($operation === 'revoke') {
  User::load($fixtures['accounts']['basic']['id'])->removeRole('translation_ui_operator')->save();
}
elseif ($operation === 'tamper' || $operation === 'restore') {
  $jobs = \Drupal::service('labdoo_common.translation_jobs');
  $job = $jobs->load($id, User::load(1));
  $path = \Drupal::service('file_system')->realpath('private://labdoo-translations') . '/' . $id . '/output.es.po';
  if ($operation === 'tamper') { copy($jobs->result($job, 'es'), $directory . '/original.po'); file_put_contents($path, "\n# changed\n", FILE_APPEND); }
  else { copy($directory . '/original.po', $path); unlink($directory . '/original.po'); }
}
elseif ($operation === 'cleanup') {
  $data = is_file($manifest) ? json_decode(file_get_contents($manifest), TRUE, 512, JSON_THROW_ON_ERROR) : [];
  foreach ($storage->getStrings(['context' => $context]) as $string) { $string->delete(); }
  foreach (['basic', 'wiki'] as $kind) { $user = User::load($fixtures['accounts'][$kind]['id']); $user->removeRole('translation_ui_operator')->save(); }
  if ($role = Role::load('translation_ui_operator')) { $role->delete(); }
  if (isset($data['plural_before'])) { \Drupal::state()->set('locale.translation.formulae', $data['plural_before']); }
  $store = \Drupal::keyValue('labdoo.translation.jobs'); $owned = [];
  $root = \Drupal::service('file_system')->realpath('private://labdoo-translations');
  foreach ($store->getAll() as $key => $job) {
    if (is_array($job) && isset($job['id']) && ($job['agent'] ?? '') === 'fixture') {
      $owned[] = $job['id'];
      if ($root && is_dir($root . '/' . $job['id'])) {
        foreach (glob($root . '/' . $job['id'] . '/*.po') as $file) { unlink($file); }
        rmdir($root . '/' . $job['id']);
      }
      $store->delete($key);
    }
  }
  foreach ($store->getAll() as $key => $value) {
    if (is_string($value) && in_array($value, $owned, TRUE)) { $store->delete($key); }
    elseif (str_starts_with($key, 'owner:') && is_array($value)) { $store->set($key, array_values(array_diff($value, $owned))); }
  }
  \Drupal::queue(TranslationJobs::QUEUE)->deleteQueue();
  if (is_dir($directory)) { foreach (glob($directory . '/*') as $file) { unlink($file); } rmdir($directory); }
  if (is_file($manifest)) { unlink($manifest); }
}
if ($operation !== 'cleanup') {
  $data = is_file($manifest) ? json_decode(file_get_contents($manifest), TRUE, 512, JSON_THROW_ON_ERROR) : [];
  $translations = [];
  foreach ($data['lids'] ?? [] as $lid) {
    foreach (['es', 'fr'] as $language) {
      $translation = $storage->findTranslation(['lid' => $lid, 'language' => $language]);
      if ($translation && !$translation->isNew()) { $translations[$language][$lid] = $translation->getString(); }
    }
  }
  $job = $id ? \Drupal::keyValue('labdoo.translation.jobs')->get($id) : NULL;
  echo 'RESULT:' . json_encode(['job_id' => $id, 'calls' => is_file($directory . '/calls') ? substr_count(file_get_contents($directory . '/calls'), "\n") : 0, 'translations' => (object) $translations, 'first_lid' => $data['lids'][0] ?? NULL, 'queue' => \Drupal::queue(TranslationJobs::QUEUE)->numberOfItems(), 'job_state' => $job['state'] ?? NULL]) . "\n";
}
