<?php
/** Batched identity resolution with repeated users/nodes and explicit freshness. */
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\labdoo_migrate\Services\Incremental\{NodeSourceReader, NodeSyncAdapter};
$db = Database::getConnection();
if (!Settings::get('labdoo_e2e') || $db->getConnectionOptions()['database'] !== 'labdoo_e2e_incremental_dest_20261109') { throw new RuntimeException('Independent fixture required.'); }
$lab = dirname(__DIR__, 3);
$options = json_decode(file_get_contents($lab . '/state/incremental-source-writer-connection.json'), TRUE, 512, JSON_THROW_ON_ERROR);
if ($options['database'] !== 'labdoo_e2e_incremental_source_20261109') { throw new RuntimeException('Unsafe source fixture.'); }
Database::addConnectionInfo('prefetch_writer', 'default', $options);
$writer = Database::getConnection('default', 'prefetch_writer');
$source = Database::getConnection('default', 'external');
$integer = ['type' => 'int', 'not null' => TRUE, 'default' => 0];
$string = ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''];
$common = array_fill_keys(['entity_id','revision_id','deleted','delta'], $integer) + array_fill_keys(['entity_type','bundle','language'], $string);
$tables = ['field_data_field_hub_laptop' => 'field_hub_laptop_target_id', 'field_data_field_edoovillage_destination' => 'field_edoovillage_destination_target_id'];
$created = [];
$ids = range(120001,120200);
$parents = [510001,510002,510003];
$assert = static function(bool $condition, string $message): void { if (!$condition) { throw new LogicException($message); } };
$assert(!$writer->select('node','n')->condition('nid', array_merge($ids,$parents),'IN')->countQuery()->execute()->fetchField(), 'Source fixture IDs occupied.');
$assert(!$db->select('node','n')->condition('nid',array_merge($ids,[900001,900002]),'IN')->countQuery()->execute()->fetchField(), 'Destination fixture IDs occupied.');
$previous = \Drupal::state()->get('labdoo_migrate_is_running', NULL);
$field = FieldConfig::load('node.team_post.body'); $translatable = $field->isTranslatable();
$transaction = $db->startTransaction();
$passed=[]; $counts=[];
try {
  \Drupal::state()->set('labdoo_migrate_is_running', TRUE);
  if (!User::load(1)) { User::create(['uid'=>1,'name'=>'reference_fixture_admin','status'=>1])->save(); }
  foreach ($tables as $table=>$column) {
    if (!$writer->schema()->tableExists($table)) { $writer->schema()->createTable($table,['fields'=>$common+[$column=>$integer]]);$created[]=$table; }
    $writer->insert('field_config_instance')->fields(['field_name'=>substr($table,11),'entity_type'=>'node','bundle'=>'laptop','deleted'=>0])->execute();
  }
  foreach ([[510001,'hub',0,'en'],[510002,'edoovillage',0,'en'],[510003,'hub',510001,'es']] as [$nid,$type,$tnid,$language]) {
    $writer->insert('node')->fields(['nid'=>$nid,'vid'=>$nid,'tnid'=>$tnid,'type'=>$type,'language'=>$language,'uid'=>8000077,'status'=>1,'created'=>50,'changed'=>100,'title'=>'Synthetic parent'])->execute();
  }
  foreach ($ids as $nid) {
    $writer->insert('node')->fields(['nid'=>$nid,'vid'=>$nid,'tnid'=>0,'type'=>'laptop','language'=>'en','uid'=>8000077,'status'=>1,'created'=>50,'changed'=>100,'title'=>'Synthetic repeated references'])->execute();
    foreach ($tables as $table=>$column) {
      $target = str_contains($table,'hub_laptop') ? ($nid%2 ? 510001 : 510003) : 510002;
      $writer->insert($table)->fields(['entity_id'=>$nid,'revision_id'=>$nid,'deleted'=>0,'delta'=>0,'entity_type'=>'node','bundle'=>'laptop','language'=>'und',$column=>$target])->execute();
    }
  }
  foreach (['hub'=>900001,'edoovillage'=>900002] as $bundle=>$nid) {
    $node=Node::create(['type'=>$bundle,'nid'=>$nid,'title'=>'Synthetic target','uid'=>0]);
    $node->original_entity_id=$nid;$node->skip_action_generation=TRUE;$node->skip_geocoding_enqueue=TRUE;$node->skip_recompute_enqueue=TRUE;$node->labdoo_skip_geocoding=TRUE;$node->save();
  }
  foreach ($ids as $nid) {
    $node=Node::create(['type'=>'dootronic','nid'=>$nid,'title'=>'Synthetic repeated references','uid'=>1,'field_hub'=>900001,'field_edoovillage_destination'=>900002]);
    $node->original_entity_id=$nid;$node->skip_action_generation=TRUE;$node->skip_geocoding_enqueue=TRUE;$node->skip_recompute_enqueue=TRUE;$node->labdoo_skip_geocoding=TRUE;$node->save();
    $db->insert('labdoo_migrate_tracking')->fields(['entity_type'=>'node','bundle'=>'dootronic','source_id'=>$nid,'destination_id'=>$nid,'last_migrated'=>time(),'duration_ms'=>0])->execute();
  }
  foreach ([['node','hub',510001,900001],['node','edoovillage',510002,900002],['user','user',8000077,1]] as [$entityType,$bundle,$sourceId,$destinationId]) {
    $db->merge('labdoo_migrate_tracking')->keys(['entity_type'=>$entityType,'bundle'=>$bundle,'source_id'=>$sourceId])->fields(['destination_id'=>$destinationId,'last_migrated'=>time(),'duration_ms'=>0])->execute();
  }
  $mapping=['source_type'=>'laptop','entity_type'=>'node','fields_mapping'=>[
    ['source'=>['fields'=>[['table'=>'node','field'=>'uid','key'=>'nid']]]],
    ['source'=>['fields'=>[['table'=>'field_data_field_hub_laptop','field'=>'field_hub_laptop_target_id','key'=>'entity_id']]]],
    ['source'=>['fields'=>[['table'=>'field_data_field_edoovillage_destination','field'=>'field_edoovillage_destination_target_id','key'=>'entity_id']]]],
  ]];
  $reader=new NodeSourceReader($source,$mapping);
  $adapter=new NodeSyncAdapter($source,$db,$reader);
  $reader->fingerprints('laptop',$ids);
  $log=Database::startLog('prefetch_source','external');
  $destLog=Database::startLog('prefetch_destination');
  $started=microtime(TRUE);$adapter->destinations('laptop',$ids);
  $counts['prefetch_source_queries']=count($log->get('prefetch_source'));
  $counts['prefetch_destination_queries']=count($destLog->get('prefetch_destination'));
  $counts['prefetch_seconds']=round(microtime(TRUE)-$started,4);
  $comparisonStarted=microtime(TRUE);
  foreach($ids as $nid) { $adapter->compare('laptop',$nid,$nid); }
  $counts['comparison_seconds']=round(microtime(TRUE)-$comparisonStarted,4);
  $assert(count($log->get('prefetch_source'))===$counts['prefetch_source_queries'] && count($destLog->get('prefetch_destination'))===$counts['prefetch_destination_queries'],'Comparisons reloaded destination nodes or reference metadata.');
  $passed[]='200 populated destination comparisons reuse loaded nodes with zero extra SQL';
  $reference=new ReflectionMethod($adapter,'reference');
  for($i=0;$i<1000;$i++) {
    $assert($reference->invoke($adapter,'user',8000077)===1,'Repeated author identity differs.');
    $assert($reference->invoke($adapter,'node',$i%2?510001:510003)===900001,'Root/translation node identity differs.');
    $assert($reference->invoke($adapter,'node',510002)===900002,'Parent identity differs.');
  }
  $assert(count($log->get('prefetch_source'))===$counts['prefetch_source_queries'] && count($destLog->get('prefetch_destination'))===$counts['prefetch_destination_queries'],'Repeated references executed additional SQL.');
  $assert($counts['prefetch_source_queries']<=2,'Reference source prefetch is not batched.');
  $passed[]='200 nodes / 3000 repeated reference lookups reuse bounded batch metadata with zero extra SQL';
  foreach(array_merge($log->get('prefetch_source'),$destLog->get('prefetch_destination')) as $query) {
    $assert((bool)preg_match('/^(SELECT|SHOW|DESCRIBE)\b/i',ltrim($query['query'])),'Prefetch wrote database state.');
  }
  $passed[]='reference prefetch and lookups issue read-only SQL';
  $db->update('labdoo_migrate_tracking')->fields(['destination_id'=>0])->condition('entity_type','user')->condition('bundle','user')->condition('source_id',8000077)->execute();
  $adapter->resetRuntime();
  $assert($reference->invoke($adapter,'user',8000077)===0,'Runtime reset masked changed user mapping.');
  $passed[]='runtime reset observes changed destination identity, including anonymous UID zero';
  $db->insert('labdoo_migrate_tracking')->fields(['entity_type'=>'node','bundle'=>'gallery','source_id'=>510002,'destination_id'=>900001,'last_migrated'=>time(),'duration_ms'=>0])->execute();
  $adapter->resetRuntime();
  $assert($reference->invoke($adapter,'node',510002)===NULL,'Ambiguous cross-bundle source mapping was accepted.');
  $passed[]='ambiguous reference remains unresolved rather than using matching numeric IDs';
  $before=$adapter->context('team_page');
  $field->setTranslatable(!$translatable)->save();
  $assert($adapter->context('team_page')===$before,'Context changed within a batch.');
  $adapter->resetRuntime();
  $assert($adapter->context('team_page')['mapping_hash']!==$before['mapping_hash'],'Runtime reset did not invalidate changed field configuration.');
  $passed[]='context cache is stable per batch and invalidates field configuration on reset';
  echo json_encode(['passed'=>count($passed),'cases'=>$passed,'counts'=>$counts],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}
finally {
  $field->setTranslatable($translatable)->save();
  $transaction->rollBack();
  \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  if($previous===NULL){\Drupal::state()->delete('labdoo_migrate_is_running');}else{\Drupal::state()->set('labdoo_migrate_is_running',$previous);}
  foreach($tables as $table=>$column) {
    $writer->delete($table)->condition('entity_id',$ids,'IN')->execute();
    $writer->delete('field_config_instance')->condition('field_name',substr($table,11))->condition('entity_type','node')->condition('bundle','laptop')->execute();
  }
  $writer->delete('node')->condition('nid',array_merge($ids,$parents),'IN')->execute();
  foreach($created as $table){$writer->schema()->dropTable($table);}
  \Drupal::service('entity.memory_cache')->deleteAll();
}
