<?php
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\labdoo_migrate\Services\Incremental\DerivedFields;
use Drupal\labdoo_migrate\Services\Incremental\ReadOnlyEntityLoader;

$db=Database::getConnection();
if(!Settings::get('labdoo_e2e')||!str_starts_with($db->getConnectionOptions()['database'],'labdoo_e2e_'))throw new RuntimeException('Isolated E2E database required.');
$base=dirname(__DIR__).'/web/modules/custom/labdoo_migrate/src/Services/Incremental/';foreach(['ReadOnlyEntityLoader','DerivedFields','NodeSourceReader','NodeSyncAdapter']as$class)require_once$base.$class.'.php';
$fixture=json_decode(file_get_contents('/var/www/html/labdoo3/.e2e-lab/state/integration-fixtures.json'),TRUE,512,JSON_THROW_ON_ERROR)['nodes'];
$oldHub=(int)$fixture['Hub Alpha']['id'];$newHub=(int)$fixture['Hub Zulu']['id'];$oldSchool=(int)$fixture['School']['id'];$main=(int)$fixture['Laptop Main']['id'];$second=(int)$fixture['Laptop Second']['id'];
$newSchool=(int)$db->select('node','n')->fields('n',['nid'])->condition('type','edoovillage')->condition('nid',$oldSchool,'<>')->orderBy('nid')->range(0,1)->execute()->fetchField();
if(!$newSchool)throw new RuntimeException('A second existing school is required.');
$targets=[$oldHub,$newHub,$oldSchool,$newSchool];$loader=ReadOnlyEntityLoader::storage('node');$passed=[];$started=microtime(TRUE);
$assert=static function(bool$ok,string$message):void{if(!$ok)throw new LogicException($message);};
$rows=static function(string$table,array$ids,string$key='entity_id')use($db):array{return$db->select($table,'f')->fields('f')->condition($key,$ids,'IN')->orderBy($key)->execute()->fetchAll(PDO::FETCH_ASSOC);};
$baseHash=static function()use($db,$targets):string{$data=[];foreach(['node','node_field_data','node_revision','node_field_revision']as$table){$r=$db->select($table,'n')->fields('n')->condition('nid',$targets,'IN')->execute()->fetchAll(PDO::FETCH_ASSOC);$s=array_map('serialize',$r);sort($s);$data[$table]=$s;}return hash('sha256',serialize($data));};
$counters=static fn():array=>$db->query('SELECT(SELECT COUNT(*) FROM {node})AS nodes,(SELECT MAX(nid)FROM {node})AS nid,(SELECT COUNT(*)FROM {node_revision})AS revisions,(SELECT MAX(vid)FROM {node_revision})AS vid')->fetchAssoc();
$beforeCounters=$counters();$metadataBefore=$baseHash();$container=\Drupal::getContainer();$trackingId='search_api.entity_datasource.tracking_manager';$tracking=$container->has($trackingId)?$container->get($trackingId):NULL;
$spy=new class{public array$ids=[];public function entityUpdate($node):void{$this->ids[]=(int)$node->id();}};
if($tracking)$container->set($trackingId,$spy);
$transaction=$db->startTransaction();
try{
 $write=static function(int$nid,string$field,$value)use($db,$loader):void{
  $node=$loader->load($nid);$vid=(int)$node->getRevisionId();$column=$field.'_'.($node->getFieldDefinition($field)->getType()==='entity_reference'?'target_id':'value');
  foreach(['node__'.$field=>FALSE,'node_revision__'.$field=>TRUE]as$table=>$revision){$delete=$db->delete($table)->condition('entity_id',$nid)->condition('deleted',0);if($revision)$delete->condition('revision_id',$vid);$delete->execute();$db->insert($table)->fields(['bundle'=>$node->bundle(),'deleted'=>0,'entity_id'=>$nid,'revision_id'=>$vid,'langcode'=>$node->getUntranslated()->language()->getId(),'delta'=>0,$column=>$value])->execute();}
 };
 // Make fixture parents exclusive without creating any node/revision. Existing
 // children of the OLD fixture parent are redirected before placing one laptop.
 foreach(['field_hub'=>[$oldHub,$newHub],'field_edoovillage_destination'=>[$oldSchool,$newSchool]]as$field=>[$old,$new]){
  $ids=$db->select('node__'.$field,'f')->fields('f',['entity_id'])->condition('bundle','dootronic')->condition($field.'_target_id',$old)->condition('deleted',0)->execute()->fetchCol();foreach(array_unique($ids)as$id)$write((int)$id,$field,$new);
 }
 $write($oldSchool,'field_hub',$oldHub);$write($newSchool,'field_hub',$newHub);$write($oldSchool,'field_number_of_laptops_needed',5);$write($newSchool,'field_number_of_laptops_needed',7);
 foreach([[$main,$oldHub,$oldSchool,'S4'],[$second,$newHub,$newSchool,'T1']]as[$id,$hub,$school,$status]){$write($id,'field_hub',$hub);$write($id,'field_edoovillage_destination',$school);$write($id,'field_dootronic_status',$status);}
 $neededBefore=$rows('node__field_number_of_laptops_needed',[$oldSchool,$newSchool]);$neededRevisionBefore=$rows('node_revision__field_number_of_laptops_needed',[$oldSchool,$newSchool]);
 $history=[];foreach($targets as$id){$n=$loader->load($id);foreach(DerivedFields::computedFields($n->bundle())as$field){$table='node_revision__'.$field;$r=$db->select($table,'f')->fields('f')->condition('entity_id',$id)->condition('revision_id',(int)$n->getRevisionId(),'<>')->execute()->fetchAll(PDO::FETCH_ASSOC);$history[$id][$table]=$r;}}
 $sourceOptions=json_decode(file_get_contents('/var/www/html/labdoo3/.e2e-lab/state/v2-readonly-connection.json'),TRUE,512,JSON_THROW_ON_ERROR);Database::addConnectionInfo('derived_hash_source','default',$sourceOptions);$source=Database::getConnection('default','derived_hash_source');
 $adapter=new \Drupal\labdoo_migrate\Services\Incremental\NodeSyncAdapter($source,$db);
 $ownedHashBefore=['hub'=>$adapter->hashes('hub',[$oldHub,$newHub]),'edoovillage'=>$adapter->hashes('edoovillage',[$oldSchool,$newSchool])];
 $helper=new DerivedFields($db);$repository=\Drupal::service('labdoo_common.repository.common');
 $assert($repository->getDootronicsCountByStatus('S4',$oldSchool)===1&&$repository->getDootronicsCountByStatus('T1',$oldSchool)===0,'Old school fixture must have exactly one delivered laptop.');
 $initial=[];foreach($targets as$id)$initial[$id]=$helper->recompute($loader->load($id));
 $get=static fn(int$id,string$field)=>$db->select('node__'.$field,'f')->fields('f',[$field.'_value'])->condition('entity_id',$id)->condition('deleted',0)->condition('delta',0)->execute()->fetchField();
 $assert((int)$get($oldSchool,'field_dootronics_delivered')===1&&(int)$get($oldSchool,'field_dootronics_remaining')===4,'Initial school counters incorrect.');$passed[]='same repository formula produces initial school counters';
 $assert(!$loader->getEntityType()->isStaticallyCacheable()&&!$loader->getEntityType()->isPersistentlyCacheable()&&(int)$loader->load($oldSchool)->get('field_dootronics_remaining')->value===4,'Isolated entity loader did not read fresh SQL counters.');$passed[]='isolated loader cache flags disabled and fresh after direct SQL';
 $newSchoolBefore=(int)$get($newSchool,'field_dootronics_delivered');$newHubBefore=(int)$get($newHub,'field_dootronics_delivered');
 $write($main,'field_hub',$newHub);$write($main,'field_edoovillage_destination',$newSchool);
 $oldHubResult=$helper->recompute($loader->load($oldHub));$oldSchoolResult=$helper->recompute($loader->load($oldSchool));
 $assert($oldHubResult['changed']&&$oldSchoolResult['changed']&&(int)$get($oldHub,'field_dootronics_delivered')===0&&(int)$get($oldSchool,'field_dootronics_delivered')===0&&(int)$get($oldSchool,'field_dootronics_remaining')===5,'Former empty parents retain stale counters.');$passed[]='old hub and school recompute after their LAST laptop moves away';
 $newHubResult=$helper->recompute($loader->load($newHub));$newSchoolResult=$helper->recompute($loader->load($newSchool));
 $assert((int)$get($newHub,'field_dootronics_delivered')===$newHubBefore+1&&(int)$get($newSchool,'field_dootronics_delivered')===$newSchoolBefore+1,'New parents did not gain delivered laptop.');$passed[]='new hub and school gain moved laptop counters';
 $needed=(int)$get($newHub,'field_dootronics_needed');$remaining=(int)$get($newHub,'field_dootronics_remaining');$expected=$needed===0?0:($needed-$remaining)*100/$needed;
 $assert(abs((float)$get($newHub,'field_dootronics_completed')-$expected)<=0.0051,'Hub decimal completion percentage differs from existing formula.');$passed[]='hub completion percentage respects numeric field scale';
 $assert($neededBefore===$rows('node__field_number_of_laptops_needed',[$oldSchool,$newSchool])&&$neededRevisionBefore===$rows('node_revision__field_number_of_laptops_needed',[$oldSchool,$newSchool]),'Authoritative school demand changed.');$passed[]='authoritative needed field and its revisions preserved';
 foreach($targets as$id){$n=$loader->load($id);foreach(DerivedFields::computedFields($n->bundle())as$field){$table='node_revision__'.$field;$r=$db->select($table,'f')->fields('f')->condition('entity_id',$id)->condition('revision_id',(int)$n->getRevisionId(),'<>')->execute()->fetchAll(PDO::FETCH_ASSOC);$assert($r===$history[$id][$table],'Historical derived revision was modified.');}}
 $passed[]='only current revision field rows change; historical revisions untouched';
 $trackedBefore=count($spy->ids);$unchanged=$helper->recompute($loader->load($oldHub));$assert(!$unchanged['changed']&&!$unchanged['tables']&&count($spy->ids)===$trackedBefore,'Noop recompute wrote/tracked a target.');$passed[]='idempotent no-op does not track or rewrite fields';
 $missing='field_dootronics_remaining';$vid=(int)$loader->load($oldHub)->getRevisionId();foreach(['node__'.$missing=>FALSE,'node_revision__'.$missing=>TRUE]as$table=>$rev){$q=$db->delete($table)->condition('entity_id',$oldHub)->condition('deleted',0);if($rev)$q->condition('revision_id',$vid);$q->execute();}
 $restored=$helper->recompute($loader->load($oldHub));$assert($restored['changed']&&$restored['tables']['node__'.$missing]['before']===[]&&count($restored['tables']['node__'.$missing]['after'])===1,'Missing current field row was not safely created.');$passed[]='missing current and current-revision derived rows created without nodes';
 $stale=clone$loader->load($oldHub);$stale->set('vid',$vid+1000000);try{$helper->recompute($stale);throw new LogicException('Stale revision accepted.');}catch(RuntimeException$e){$passed[]='stale current revision fails closed';}
 $nested=$db->startTransaction();$db->update('node__field_dootronics_delivered')->fields(['field_dootronics_delivered_value'=>999])->condition('entity_id',$oldHub)->execute();$evidence=$helper->recompute($loader->load($oldHub));$assert((int)$evidence['tables']['node__field_dootronics_delivered']['before'][0]['field_dootronics_delivered_value']===999,'Before image missing for rollback audit.');$nested->rollBack();$assert((int)$get($oldHub,'field_dootronics_delivered')===0,'Nested rollback changed persisted target.');$passed[]='raw before/after images and transactional rollback preserve target';
 $ownedHashAfter=['hub'=>$adapter->hashes('hub',[$oldHub,$newHub]),'edoovillage'=>$adapter->hashes('edoovillage',[$oldSchool,$newSchool])];$assert($ownedHashBefore===$ownedHashAfter,'Internal derived updates changed source-owned destination baseline hash.');$passed[]='source-owned adapter hash survives internal counter updates';
 $write($oldSchool,'field_number_of_laptops_needed',6);$assert($adapter->hashes('edoovillage',[$oldSchool])[$oldSchool]!==$ownedHashBefore['edoovillage'][$oldSchool],'Authoritative demand edit was hidden by hash allowlist.');$write($oldSchool,'field_number_of_laptops_needed',5);$passed[]='authoritative demand edit still changes destination baseline hash';
 $assert($metadataBefore===$baseHash()&&$beforeCounters===$counters(),'Base node metadata, IDs or revisions changed.');$passed[]='base node/title/changed/revision metadata hash and ID counters unchanged';
 $assert(!in_array('title',DerivedFields::computedFields('dootronic'),TRUE)&&!in_array('field_number_of_laptops_needed',DerivedFields::computedFields('edoovillage'),TRUE)&&!in_array('field_dootrip_capacity',DerivedFields::computedFields('dootrip'),TRUE),'Derived allowlist hides author fields.');$passed[]='allowlist excludes title, device identity, demand and trip capacity';
 echo json_encode(['passed'=>count($passed),'cases'=>$passed,'seconds'=>round(microtime(TRUE)-$started,3),'peak_memory_bytes'=>memory_get_peak_usage(TRUE),'targets'=>count($targets),'search_tracking_calls'=>count($spy->ids),'nodes_or_revisions_created'=>FALSE,'production_changes'=>FALSE],JSON_PRETTY_PRINT).PHP_EOL;
}
finally{if($tracking)$container->set($trackingId,$tracking);$transaction->rollBack();Database::removeConnection('derived_hash_source');}
