<?php
require __DIR__ . '/../e2e/support/harden.php';
$state = \Drupal\Core\Site\Settings::get('labdoo_e2e_state');
$file = $state . '/integration-fixtures.json';
$data = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
// The gallery UI intentionally restricts title creation to uid 1.
$account = \Drupal\user\Entity\User::load(1);
$password = $data['accounts']['superadmin']['password'] ?? bin2hex(random_bytes(16));
$account->setUsername('e2e_superadmin')->setEmail('e2e-superadmin@example.invalid')->setPassword($password)->activate()->save();
$data['accounts']['superadmin'] = ['id' => 1, 'name' => $account->getAccountName(), 'password' => $password];
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
foreach (['Book'=>0,'Alpha'=>10,'Zulu'=>20,'Other Book'=>30] as $key=>$weight) {
  $page=\Drupal::entityTypeManager()->getStorage('mini_wiki_page')->load($data['wiki'][$key]['id']);
  $page->set('weight',$weight)->save();
}
// CAPTCHA is an external integration; browser tests submit the real Webform
// with this integration removed only from the isolated clone.
$webform=\Drupal\webform\Entity\Webform::load('contact_labdoo');
$elements=$webform->getElementsDecoded();unset($elements['captcha']);$webform->setElements($elements)->save();
// A seeded device may have been saved by trip-reference hooks. Restore its
// controlled address after preparing those relationships. External geocoding
// is tested through deterministic providers, not imported cache entries.
$device=\Drupal\node\Entity\Node::load($data['nodes']['Laptop Main']['id']);
$device->labdoo_skip_geocoding=TRUE;
$device->skip_geocoding_enqueue=TRUE;
$device->skip_recompute_enqueue=TRUE;
$device->set('field_address',['country_code'=>'ES','locality'=>'Madrid','postal_code'=>'28001','address_line1'=>'E2E Street 42'])->save();
$index=\Drupal\search_api\Entity\Index::load('default_index');
if ($index) {
  $ids=[];foreach ($data['nodes'] as $node) $ids[]='entity:node/'.$node['id'].':en';
  foreach ($data['wiki'] as $wiki) $ids[]='entity:mini_wiki_page/'.$wiki['id'].':en';
  $index->indexSpecificItems($index->loadItemsMultiple($ids));
}
echo "Upgrade fixtures prepared.\n";
