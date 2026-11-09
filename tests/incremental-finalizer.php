<?php
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\labdoo_migrate\Services\Incremental\DeltaStore;
use Drupal\labdoo_migrate\Services\Incremental\DeltaFinalizer;

$db=Database::getConnection();
if(!Settings::get('labdoo_e2e') || !str_starts_with($db->getConnectionOptions()['database'],'labdoo_e2e_'))throw new RuntimeException('Isolated E2E database required.');
$base=dirname(__DIR__).'/web/modules/custom/labdoo_migrate/src/Services/Incremental/';
foreach(['DeltaStore','ReadOnlyEntityLoader','DerivedFields','DeltaFinalizer']as$class)require_once$base.$class.'.php';
$created=[];
foreach(DeltaStore::schema()as$table=>$definition){if(!$db->schema()->tableExists($table)){$db->schema()->createTable($table,$definition);$created[]=$table;}}
$passed=[];$start=microtime(TRUE);
$assert=static function(bool$condition,string$message):void{if(!$condition)throw new LogicException($message);};
$fixture=json_decode(file_get_contents('/var/www/html/labdoo3/.e2e-lab/state/integration-fixtures.json'),TRUE,512,JSON_THROW_ON_ERROR)['nodes'];
$hub=(int)$fixture['Hub Alpha']['id'];$trip=(int)$fixture['Trip']['id'];$dootronic=(int)$fixture['Laptop Main']['id'];$school=(int)$fixture['School']['id'];
$counters=static fn():array=>$db->query('SELECT (SELECT COUNT(*) FROM {node}) AS nodes,(SELECT MAX(nid) FROM {node}) AS nid,(SELECT COUNT(*) FROM {node_revision}) AS revisions,(SELECT MAX(vid) FROM {node_revision}) AS vid')->fetchAssoc();
$before=$counters();$container=\Drupal::getContainer();$hubService=\Drupal::service('labdoo_hub.compute');$queueService=\Drupal::service('queue');
$transaction=$db->startTransaction();
try{
 $store=new DeltaStore($db);$finalizer=new DeltaFinalizer($db);
 $seed=static function(string$bundle,int$id,array$details,string$operation='update',string$status='applied')use($store,$db):string{
  $run=$store->createRun(['source_type'=>'fixture','bundle'=>$bundle,'mapping_hash'=>str_repeat('a',64),'source_identity'=>str_repeat('b',64),'ceiling'=>1,'phase'=>'complete']);
  $db->insert(DeltaStore::ITEM)->fields(['run_id'=>$run['run_id'],'source_id'=>1,'destination_id'=>$id,'operation'=>$operation,'status'=>$status,'source_hash'=>str_repeat('c',64),'destination_hash'=>str_repeat('d',64),'source_languages'=>'["en"]','details'=>json_encode($details,JSON_THROW_ON_ERROR),'attempts'=>1,'updated'=>time()])->execute();
  return$run['run_id'];
 };
 $details=static fn(string$bundle,int$id):array=>['written'=>TRUE,'effects'=>['before'=>[$bundle=>[$id]],'after'=>[$bundle=>[$id]]],'reason'=>'preserved evidence'];
 $queueCount=static fn():int=>(int)$db->select('queue','q')->countQuery()->execute()->fetchField();
 $run=$seed('hub',$hub,$details('hub',$hub));$q=$queueCount();$r=$finalizer->finalize($run,1);
 $assert($r['processed']===1&&$r['remaining']===0&&($r['queued_targets']['hub']??0)>=1&&$queueCount()>$q,'Primary hub was not enqueued.');
 $saved=json_decode($db->select(DeltaStore::ITEM,'i')->fields('i',['details'])->condition('run_id',$run)->execute()->fetchField(),TRUE);
 $assert($saved['derived_queued']===TRUE&&$saved['reason']==='preserved evidence'&&isset($saved['effects']),'Checkpoint lost evidence.');
 $passed[]='real compute feeder enqueued and durable checkpoint preserves details';
 $q=$queueCount();$r=$finalizer->finalize($run);$assert($r['processed']===0&&$queueCount()===$q,'Repeated finalization duplicated queue work.');$passed[]='repeated call is idempotent';
 foreach([['baseline','applied'],['unchanged','applied'],['update','pending'],['update','blocked'],['update','error']]as[$op,$status]){$run=$seed('hub',$hub,$details('hub',$hub),$op,$status);$q=$queueCount();$assert($finalizer->finalize($run)['processed']===0&&$queueCount()===$q,'Nonwritten operation enqueued.');}
 $passed[]='baseline/noop/pending/blocked/error operations excluded';
 $run=$seed('hub',$hub,['written'=>FALSE]);$q=$queueCount();$r=$finalizer->finalize($run);$assert($r['not_written']===1&&$queueCount()===$q,'Noop actual-write evidence ignored.');$passed[]='written=false checkpointed without queue';
 $run=$seed('hub',$hub,[]);$q=$queueCount();try{$finalizer->finalize($run);throw new LogicException('Missing evidence accepted.');}catch(RuntimeException$e){$assert($queueCount()===$q,'Missing evidence inserted work.');$passed[]='missing actual-write evidence fails closed';}
 $run=$seed('hub',$hub,$details('hub',$hub));
 $other=new DatabaseLockBackend($db);$assert($other->acquire('labdoo_migrate.delta',60),'Could not stage lock collision.');
 try{$finalizer->finalize($run);throw new LogicException('Concurrent delta lock ignored.');}catch(RuntimeException$e){$passed[]='global delta lock blocks concurrent finalization';}finally{$other->release('labdoo_migrate.delta');}
 $d=$details('dootronic',$dootronic);$d['effects']['before']['dootrip']=[$trip];$d['effects']['before']['hub']=[$hub];$d['effects']['after']['hub']=[$hub];$d['effects']['after']['edoovillage']=[$school];
 $run=$seed('dootronic',$dootronic,$d);$q=$queueCount();$r=$finalizer->finalize($run);
 $assert(($r['queued_targets']['dootronic']??0)>=1&&($r['queued_targets']['dootrip']??0)>=1&&($r['queued_targets']['hub']??0)>=1,'Old/new related targets omitted.');
 $found=FALSE;
 foreach($db->select('queue','q')->fields('q',['data'])->condition('name','labdoo_dootronics_recompute')->execute()as$row){$dto=unserialize($row->data,['allowed_classes'=>TRUE]);$data=$dto['data']??NULL;if(is_array($data)&&($data['id']??NULL)===$dootronic&&in_array($trip,$data['original_dootrip_ids']??[],TRUE))$found=TRUE;}
 $assert($found,'Queue DTO omitted prior dootrip IDs.');$passed[]='before/after targets deduplicated and original dootrips preserved in DTO';
 $run=$seed('hub',$hub,$details('hub',$hub),'deleted');$assert($finalizer->finalize($run)['processed']===1,'Applied unpublish not finalized.');$passed[]='applied source deletion queues derived work';
 $run=$seed('hub',$hub,$details('hub',$hub));$q=$queueCount();
 $container->set('labdoo_hub.compute',new class($hubService){public function __construct(private object$original){}public function enqueueRecompute($node):void{$this->original->enqueueRecompute($node);throw new RuntimeException('Injected failure after enqueue');}});
 try{$finalizer->finalize($run);throw new LogicException('Injected failure not propagated.');}catch(RuntimeException$e){$assert($queueCount()===$q,'Queue work escaped checkpoint transaction rollback.');$d=json_decode($db->select(DeltaStore::ITEM,'i')->fields('i',['details'])->condition('run_id',$run)->execute()->fetchField(),TRUE);$assert(empty($d['derived_queued']),'Failed enqueue advanced checkpoint.');$passed[]='failure after real enqueue rolls back queue and checkpoint atomically';}finally{$container->set('labdoo_hub.compute',$hubService);}
 $assert($finalizer->finalize($run)['processed']===1,'Failed finalization could not resume.');$passed[]='retry resumes after atomic rollback';
 $run=$seed('hub',$hub,$details('hub',$hub));$container->set('queue',new class{public function get($name,$reliable=FALSE){return new stdClass();}});
 try{$finalizer->finalize($run);throw new LogicException('Nontransactional queue accepted.');}catch(RuntimeException$e){$passed[]='non-database queue backend fails closed';}finally{$container->set('queue',$queueService);}
 $run=$seed('hub',$trip,$details('hub',$trip));try{$finalizer->finalize($run);throw new LogicException('Wrong destination bundle accepted.');}catch(RuntimeException$e){$passed[]='changed destination identity fails closed';}
 $run=$seed('hub',$hub,$details('hub',$hub));$second=(int)$fixture['Hub Zulu']['id'];
 $db->insert(DeltaStore::ITEM)->fields(['run_id'=>$run,'source_id'=>2,'destination_id'=>$second,'operation'=>'create','status'=>'applied','source_hash'=>str_repeat('c',64),'destination_hash'=>str_repeat('d',64),'source_languages'=>'["en"]','details'=>json_encode($details('hub',$second),JSON_THROW_ON_ERROR),'attempts'=>1,'updated'=>time()])->execute();
 $a=$finalizer->finalize($run,1);$b=$finalizer->finalize($run,1);$assert($a['processed']===1&&$a['remaining']===1&&$b['processed']===1&&$b['remaining']===0,'Bounded finalization failed to resume next item.');$passed[]='limit applies to manifest items and resumes remaining work';
 $bad=$details('hub',$hub);$bad['derived_queued']=1;$run=$seed('hub',$hub,$bad);try{$finalizer->finalize($run);throw new LogicException('Nonboolean checkpoint accepted.');}catch(RuntimeException$e){$passed[]='nonboolean checkpoint fails closed';}
 $d=$details('dootrip',$trip);$d['effects']['before']['dootronic']=[$dootronic];$run=$seed('dootrip',$trip,$d);$finalizer->finalize($run);$found=FALSE;
 foreach($db->select('queue','q')->fields('q',['data'])->condition('name','labdoo_dootrip_calculate_capacity')->execute()as$row){$dto=unserialize($row->data,['allowed_classes'=>TRUE]);$data=$dto['data']??NULL;if(is_array($data)&&($data['id']??NULL)===$trip&&in_array($dootronic,$data['original_dootronic_ids']??[],TRUE))$found=TRUE;}
 $assert($found,'Capacity queue omitted original dootronic IDs.');$passed[]='capacity DTO preserves original dootronic IDs';
 $assert($before===$counters(),'Finalizer created/saved nodes or revisions.');$passed[]='all node IDs and revision counters unchanged';
 echo json_encode(['passed'=>count($passed),'cases'=>$passed,'seconds'=>round(microtime(TRUE)-$start,3),'peak_memory_bytes'=>memory_get_peak_usage(TRUE),'queue_workers_executed'=>FALSE,'writes'=>'only transactional queue/manifest fixtures; rollback in finally'],JSON_PRETTY_PRINT).PHP_EOL;
}
finally{$container->set('labdoo_hub.compute',$hubService);$container->set('queue',$queueService);$transaction->rollBack();foreach(array_reverse($created)as$table)$db->schema()->dropTable($table);}
