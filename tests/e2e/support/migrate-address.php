<?php
require __DIR__ . '/harden.php';
use Drupal\Core\Database\Database;
use Drupal\labdoo_migrate\Services\SpecialFieldTypes\SpecialFieldTypeFactory;
$external = Database::getConnection('default', 'external');
if (!(int)$external->query('SELECT @@session.tx_read_only')->fetchField()) throw new RuntimeException('Legacy source is not read only');
$row = $external->query("SELECT n.nid,l.country,l.city,l.postal_code,l.street FROM {location} l JOIN {location_instance} li ON li.lid=l.lid JOIN {node} n ON n.nid=li.nid WHERE n.type='laptop' AND n.status=1 AND l.street<>'' AND l.city<>'' AND l.postal_code<>'' AND LENGTH(l.country)=2 ORDER BY n.nid LIMIT 1")->fetchAssoc();
if (!$row) throw new RuntimeException('No legacy address fixture available');
$state = \Drupal\Core\Site\Settings::get('labdoo_e2e_state');
$kind = \Drupal\Core\Site\Settings::get('labdoo_e2e_variant');
$f = json_decode(file_get_contents($state.'/'.$kind.'-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load($f['accounts']['admin']['id']));
$n = \Drupal\node\Entity\Node::load($f['nodes']['Laptop Main']['id'])->createDuplicate();
$n->setTitle('E2E legacy address')->set('field_model','E2E legacy address')->set('field_serial_number','E2E-LEGACY-ADDRESS');
$n->skip_geocoding_enqueue = TRUE; $n->skip_recompute_enqueue = TRUE;
$module = \Drupal::service('extension.list.module')->getPath('labdoo_migrate');
$config = json_decode(file_get_contents(DRUPAL_ROOT.'/'.$module.'/config/fields_mapping/laptop.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$fields = array_values(array_filter($config['fields_mapping'], static fn($x) => $x['destination']['field']==='field_address'));
if (count($fields)!==4) throw new RuntimeException('Address mapping must contain four components');
$models = \Drupal::service('labdoo_migrate.mapper.fields_mapper')->buildMapping($fields);
$address = [];
foreach ($models as $model) {
 $source = $model->getSourceField()[0]->getFieldName();
 $special = $model->getDestinationField()->getSpecialType();
 $value = SpecialFieldTypeFactory::get($special->getType())->getValue($row[$source],$special->getMetadata(),$n,'en');
 if ($value) $address = array_merge($address,$value);
}
if (count($address)!==4) throw new RuntimeException('Incomplete mapped address');
$n->set('field_address', $address)->set('field_country',strtoupper($row['country']))->set('field_city',$row['city']);
$n->save();
// Personal source values stay inside ignored, private test state.
umask(0077);
file_put_contents($state.'/legacy-address-fixture.json', json_encode(['id'=>(int)$n->id(),'path'=>'/en/node/'.$n->id(),'expected'=>$address,'source_read_only'=>TRUE],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'Legacy read-only address mapping saved in isolated fixture.'.PHP_EOL;
