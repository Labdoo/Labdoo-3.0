<?php
/**
 * @file
 * Exercises transactions on disposable tables in this checkout's guarded clone.
 */

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\labdoo_migrate\Services\Incremental\{DeltaEngine,DeltaStore};

require __DIR__ . '/e2e/support/assert-isolated.php';
if (realpath(\Drupal::root()) !== realpath(dirname(__DIR__) . '/web')
  || \Drupal\Core\Site\Settings::get('labdoo_e2e_state') !== __DIR__ . '/upgrade/.local/state') {
  throw new RuntimeException('Use this checkout\'s prepared local regression clone.');
}
// Reject deprecated fetch modes even while Drupal 11 still accepts integers.
set_error_handler(static function (int $severity, string $message): bool {
  if ($severity === E_USER_DEPRECATED && (str_contains($message, 'fetchAll()') || str_contains($message, 'fetchAllAssoc()'))) {
    throw new LogicException($message);
  }
  return FALSE;
});
$connectionKey = 'incremental_engine_regression';
$options = \Drupal::database()->getConnectionOptions();
$options['prefix'] = 'delta_regression_' . bin2hex(random_bytes(6)) . '_';
Database::addConnectionInfo($connectionKey, 'default', $options);
$db = Database::getConnection('default', $connectionKey);
$temporaryTables = [];
try {
foreach (DeltaStore::schema() as $table => $schema) {
  $db->schema()->createTable($table, $schema);
  $temporaryTables[] = $table;
}
$db->schema()->createTable('fixture_targets', ['fields' => ['id' => ['type' => 'int', 'not null' => TRUE], 'payload' => ['type' => 'text', 'not null' => TRUE], 'writes' => ['type' => 'int', 'not null' => TRUE, 'default' => 0]], 'primary key' => ['id']]);
$temporaryTables[] = 'fixture_targets';
class EngineFixtureSource {
  public array $rows=[]; public int $largestBatch=0;public int $fingerprintCalls=0;
  public function maxId($type){$ids=array_keys(array_filter($this->rows,fn($r)=>$r['type']===$type));return $ids?max($ids):0;}
  public function page($type,$after,$limit,$ceiling){$this->largestBatch=max($this->largestBatch,$limit);$r=array_filter($this->rows,fn($r,$id)=>$id>$after&&$id<=$ceiling&&$r['type']===$type&&($r['root']??TRUE),ARRAY_FILTER_USE_BOTH);ksort($r);return array_slice($r,0,$limit,TRUE);}
  public function fingerprints($type,$ids){$this->fingerprintCalls++;$r=[];foreach($ids as $id)if(isset($this->rows[$id])&&$this->rows[$id]['type']===$type)$r[$id]=['hash'=>hash('sha256',$this->rows[$id]['payload']), 'languages'=>$this->rows[$id]['languages']??['en']];return $r;}
  public function existing($ids){$r=[];foreach($ids as $id)if(isset($this->rows[$id]))$r[$id]=$this->rows[$id]['type'];return $r;}
  public function rootIds($ids){return array_values(array_filter($ids,fn($id)=>isset($this->rows[$id])&&($this->rows[$id]['root']??TRUE)));}
  public function refresh(){}
}
class EngineFixtureAdapter {
  public array $lastExpected=[];public array $unsupported=[];public string $mapping='mapping';public array $conflicts=[];public ?int $fail=NULL;public bool $failAfterWrite=FALSE; public $duringWrite=NULL;
  public function __construct(public $db,public $source){}
  public function context($type){return ['bundle'=>'fixture','mapping_hash'=>hash('sha256',$this->mapping)];}
  public function destinations($type,$ids){$r=[];foreach($ids as $id){$p=$this->db->select('fixture_targets','t')->fields('t')->condition('id',$id)->execute()->fetchAssoc();$r[$id]=['id'=>$p?$id:NULL,'hash'=>$p?hash('sha256',$p['payload']):''];if(isset($this->conflicts[$id]))$r[$id]['conflict']=$this->conflicts[$id];}return $r;}
  public function trackedPage($type,$after,$limit){$ids=$this->db->select('fixture_targets','t')->fields('t',['id'])->condition('id',$after,'>')->orderBy('id')->range(0,$limit)->execute()->fetchCol();return $this->destinations($type,$ids);}
  public function compare($type,$id,$target){$d=$this->destinations($type,[$id])[$id];return ['unsupported'=>$this->unsupported,'equal'=>$d['hash']===$this->source->fingerprints($type,[$id])[$id]['hash'],'differences'=>['payload']];}
  public function apply($type,$id,$target,$op,$expected){
    $this->lastExpected=$expected;
    if($this->fail===$id&&!$this->failAfterWrite)throw new RuntimeException('Injected prewrite crash');
    $payload=$op==='unpublish'?'UNPUBLISHED':$this->source->rows[$id]['payload'];
    $old=$this->db->select('fixture_targets','t')->fields('t',['writes'])->condition('id',$id)->execute()->fetchField();
    $this->db->merge('fixture_targets')->key('id',$id)->fields(['payload'=>$payload,'writes'=>(int)$old+1])->execute();
    if($this->duringWrite)($this->duringWrite)($id);
    if($this->fail===$id)throw new RuntimeException('Injected postwrite crash');
    return ['destination_id'=>$id,'destination_hash'=>hash('sha256',$payload)];
  }
}
$passed=[];$assert=static function($c,$m){if(!$c)throw new LogicException($m);};
$reset=static function()use($db){foreach([DeltaStore::ITEM,DeltaStore::RUN,DeltaStore::SNAPSHOT,'fixture_targets'] as $t)$db->delete($t)->execute();};
$fixture=static function()use($db){$s=new EngineFixtureSource;$a=new EngineFixtureAdapter($db,$s);$store=new DeltaStore($db);return [$s,$a,$store,new DeltaEngine($s,$a,$store,new NullLockBackend,hash('sha256','fixture-source'))];};
$source=static function($s,$id,$payload='A'){$s->rows[$id]=['type'=>'fixture','payload'=>$payload];};
$target=static function($id,$payload='A')use($db){$db->insert('fixture_targets')->fields(['id'=>$id,'payload'=>$payload,'writes'=>0])->execute();};
$writes=static fn()=>(int)$db->query('SELECT COALESCE(SUM(writes),0) FROM {fixture_targets}')->fetchField();
$expectFailure=static function($fn,$text)use($assert){try{$fn();}catch(Throwable $e){$assert(str_contains($e->getMessage(),$text),'Unexpected error: '.$e->getMessage());return; }throw new LogicException('Expected failure '.$text);};
$case=static function($name,$fn)use(&$passed,$reset){$reset();$fn();$passed[]=$name;};
$case('first adoption equal has zero content writes and retry idempotence',function()use($fixture,$source,$target,$assert,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1);$target(1);$p=$e->plan('fixture');$r=$e->apply($p['run_id']);$assert($r['operations']['baseline']['applied']===1&&$writes()===0,'Equal adoption wrote content');$e->apply($p['run_id']);$assert($writes()===0&&count($st->baselines('fixture',[1]))===1,'Retry changed content');});
$case('first adoption differing blocked until explicit source wins',function()use($fixture,$source,$target,$assert,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1,'V2');$target(1,'V3');$p=$e->plan('fixture');$r=$e->apply($p['run_id']);$assert($r['operations']['update_candidate']['blocked']===1&&$writes()===0&&!$st->baselines('fixture',[1]),'Difference blessed');$e->apply($p['run_id'],['source_wins'=>TRUE]);$assert($writes()===1,'Explicit update missing');});
$case('local edit is preserved without resetting baseline; later source edit conflicts',function()use($fixture,$source,$target,$assert,$db,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1);$target(1);$p=$e->plan('fixture');$e->apply($p['run_id']);$before=$st->baselines('fixture',[1]);$db->update('fixture_targets')->fields(['payload'=>'LOCAL'])->condition('id',1)->execute();$p=$e->plan('fixture');$r=$e->apply($p['run_id']);$assert(isset($r['operations']['local_change']['preserved'])&&$before===$st->baselines('fixture',[1]),'Local edits reset baseline');$source($s,1,'SOURCE');$p=$e->plan('fixture');$r=$e->apply($p['run_id'],['source_wins'=>TRUE]);$assert(isset($r['operations']['conflict']['blocked'])&&$writes()===0,'Both sides overwrite');});
$case('source-only field change updates from valid baseline',function()use($fixture,$source,$target,$assert,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1);$target(1);$p=$e->plan('fixture');$e->apply($p['run_id']);$source($s,1,'changed-field-same-timestamp');$p=$e->plan('fixture');$r=$e->apply($p['run_id']);$assert(isset($r['operations']['update']['applied'])&&$writes()===1,'Source-only edit not applied');});
$case('deletion report then explicit unpublish preserves target identity',function()use($fixture,$source,$target,$assert,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1);$target(1);$p=$e->plan('fixture');$e->apply($p['run_id']);unset($s->rows[1]);$p=$e->plan('fixture');$r=$e->apply($p['run_id']);$assert(isset($r['operations']['deleted']['reported'])&&$writes()===0,'Deletion wrote by default');$e->apply($p['run_id'],['missing_source'=>'unpublish']);$assert($writes()===1&&$a->destinations('fixture',[1])[1]['id']===1,'Unpublish removed identity');});
$case('retyping and ambiguous identity always block source-wins',function()use($fixture,$source,$target,$assert,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1);$s->rows[1]['type']='other';$target(1);$source($s,2);$target(2);$a->conflicts[2]='ambiguous';$p=$e->plan('fixture');$r=$e->apply($p['run_id'],['source_wins'=>TRUE,'missing_source'=>'unpublish']);$assert($r['operations']['conflict']['blocked']===2&&$writes()===0,'Identity was bypassed');});
$case('partial inventory cannot apply; bounded resume keeps original ceiling',function()use($fixture,$source,$assert,$expectFailure,$writes){[$s,$a,$st,$e]=$fixture();foreach(range(1,7)as$id)$source($s,$id);$p=$e->plan('fixture',['batch_size'=>2,'max_items'=>3]);$assert($p['cursor']===3,'Bad partial cursor');$expectFailure(fn()=>$e->apply($p['run_id']),'Finish');$source($s,8);$p=$e->plan('fixture',['run_id'=>$p['run_id'],'batch_size'=>2]);$assert($p['source_ceiling']===7&&$p['operations']['create']['pending']===7&&$s->largestBatch===2,'Ceiling/batch drift');$e->apply($p['run_id'],['max_items'=>2,'batch_size'=>2]);$assert($writes()===2,'Apply exceeded bound');$e->apply($p['run_id'],['batch_size'=>2]);$assert($writes()===7,'Resume skipped entities');});
$case('preview no metadata or content writes',function()use($fixture,$source,$target,$assert,$writes,$db){[$s,$a,$st,$e]=$fixture();$source($s,1);$target(2);$p=$e->plan('fixture',['dry_run'=>TRUE]);$assert($p['read_only']&&$writes()===0,'Preview content write');foreach([DeltaStore::RUN,DeltaStore::ITEM,DeltaStore::SNAPSHOT]as$t)$assert((int)$db->select($t,'t')->countQuery()->execute()->fetchField()===0,'Preview metadata write');});
$case('source and destination plan/apply races block without baseline',function()use($fixture,$source,$target,$assert,$expectFailure,$writes,$db){[$s,$a,$st,$e]=$fixture();$source($s,1);$p=$e->plan('fixture');$source($s,1,'race');$expectFailure(fn()=>$e->apply($p['run_id']),'Source changed');$assert($writes()===0&&!$st->baselines('fixture',[1]),'Source race advanced');$source($s,2);$target(2);$p=$e->plan('fixture');$db->update('fixture_targets')->fields(['payload'=>'RACE'])->condition('id',2)->execute();$expectFailure(fn()=>$e->apply($p['run_id']),'Destination identity');$assert(!$st->baselines('fixture',[2]),'Destination race advanced');});
$case('postwrite crash rolls back destination and baseline; retry skips committed entity',function()use($fixture,$source,$assert,$expectFailure,$writes,$db){[$s,$a,$st,$e]=$fixture();foreach([1,2,3]as$id)$source($s,$id);$p=$e->plan('fixture');$a->fail=2;$a->failAfterWrite=TRUE;$expectFailure(fn()=>$e->apply($p['run_id']),'Injected postwrite');$assert($writes()===1&&count($st->baselines('fixture',[1,2,3]))===1,'Crash did not rollback');$a->fail=NULL;$r=$e->apply($p['run_id'],['retry_errors'=>TRUE]);$assert($writes()===3&&$r['phase']==='complete','Retry lost or duplicated earlier entity');});
$case('source changes during write rolls back content and progress',function()use($fixture,$source,$assert,$expectFailure,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1);$p=$e->plan('fixture');$a->duringWrite=function($id)use($s){$s->rows[$id]['payload']='CONCURRENT';};$expectFailure(fn()=>$e->apply($p['run_id']),'Source changed during write');$assert($writes()===0&&!$st->baselines('fixture',[1]),'Midwrite source race committed');});
$case('source identity and mapping resume mismatches fail closed',function()use($fixture,$source,$expectFailure,$db){[$s,$a,$st,$e]=$fixture();$source($s,1);$p=$e->plan('fixture');$a->mapping='changed';$expectFailure(fn()=>$e->apply($p['run_id']),'mapping');$a->mapping='mapping';$other=new DeltaEngine($s,$a,$st,new NullLockBackend,hash('sha256','other-source'));$expectFailure(fn()=>$other->apply($p['run_id']),'Source connection');});
$case('source deletion never unpublishes locally edited baseline',function()use($fixture,$source,$target,$assert,$writes,$db){[$s,$a,$st,$e]=$fixture();$source($s,1);$target(1);$p=$e->plan('fixture');$e->apply($p['run_id']);$db->update('fixture_targets')->fields(['payload'=>'LOCAL'])->condition('id',1)->execute();unset($s->rows[1]);$p=$e->plan('fixture');$r=$e->apply($p['run_id'],['missing_source'=>'unpublish','source_wins'=>TRUE]);$assert(isset($r['operations']['conflict']['blocked'])&&$writes()===0,'Deleted source overrode local edit');});
$case('unbaselined deletion requires explicit source-wins and bounded retry',function()use($fixture,$target,$assert,$writes){[$s,$a,$st,$e]=$fixture();$target(1);$p=$e->plan('fixture');$r=$e->apply($p['run_id'],['missing_source'=>'unpublish']);$assert(isset($r['operations']['deleted']['blocked'])&&$writes()===0,'Unbaselined deletion unprotected');$e->apply($p['run_id'],['missing_source'=>'unpublish','source_wins'=>TRUE]);$assert($writes()===1,'Explicit unbaselined deletion did not retry');});
$case('root becoming a translation is conflict, never deletion',function()use($fixture,$source,$target,$assert,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1);$s->rows[1]['root']=FALSE;$target(1);$p=$e->plan('fixture');$r=$e->apply($p['run_id'],['missing_source'=>'unpublish','source_wins'=>TRUE]);$assert(isset($r['operations']['conflict']['blocked'])&&$writes()===0,'Root membership change ignored');});
$case('locally removed baselined destination never recreated',function()use($fixture,$source,$target,$assert,$writes,$db){[$s,$a,$st,$e]=$fixture();$source($s,1);$target(1);$p=$e->plan('fixture');$e->apply($p['run_id']);$db->delete('fixture_targets')->execute();$p=$e->plan('fixture');$r=$e->apply($p['run_id'],['source_wins'=>TRUE]);$assert(isset($r['operations']['conflict']['blocked'])&&$writes()===0,'Locally deleted destination recreated');});
$case('unsupported projection cannot be bypassed by source-wins',function()use($fixture,$source,$target,$assert,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1,'NEW');$target(1,'OLD');$a->unsupported=['missing reference'];$p=$e->plan('fixture');$r=$e->apply($p['run_id'],['source_wins'=>TRUE]);$assert(isset($r['operations']['unsupported']['blocked'])&&$writes()===0,'Unsupported data overwritten');});
$case('source language provenance reaches removal apply guard',function()use($fixture,$source,$target,$assert){[$s,$a,$st,$e]=$fixture();$source($s,1);$s->rows[1]['languages']=['en','es'];$target(1);$p=$e->plan('fixture');$e->apply($p['run_id']);$source($s,1,'removed-es');$s->rows[1]['languages']=['en'];$p=$e->plan('fixture');$e->apply($p['run_id']);$assert($a->lastExpected['source_languages']===['en','es'],'Prior source language ownership lost');$b=$st->baselines('fixture',[1])[1];$assert(json_decode($b['source_languages'],TRUE)===['en'],'New language baseline stale');});
$case('preview acquires no persistent lock',function()use($fixture,$source,$assert){[$s,$a,$st,$e]=$fixture();$source($s,1);$lock=new class extends NullLockBackend { public int $calls=0;public function acquire($name,$timeout=30.0){$this->calls++;return TRUE;}};$e=new DeltaEngine($s,$a,$st,$lock,hash('sha256','fixture-source'));$e->plan('fixture',['dry_run'=>TRUE]);$assert($lock->calls===0,'Read-only preview wrote a persistent lock');});
$case('invalid batch bounds fail before producing metadata',function()use($fixture,$expectFailure,$db,$assert){[$s,$a,$st,$e]=$fixture();foreach([0,501] as $size)$expectFailure(fn()=>$e->plan('fixture',['batch_size'=>$size]),'batch size');$assert((int)$db->select(DeltaStore::RUN,'r')->countQuery()->execute()->fetchField()===0,'Invalid options wrote metadata');});
$case('failed retry retains source-language provenance',function()use($fixture,$source,$target,$assert,$expectFailure){[$s,$a,$st,$e]=$fixture();$source($s,1);$s->rows[1]['languages']=['en','es'];$target(1);$p=$e->plan('fixture');$e->apply($p['run_id']);$source($s,1,'es removed');$p=$e->plan('fixture');$a->fail=1;$expectFailure(fn()=>$e->apply($p['run_id']),'Injected prewrite');$a->fail=NULL;$e->apply($p['run_id'],['retry_errors'=>TRUE]);$assert($a->lastExpected['source_languages']===['en','es'],'Retry lost guarded language provenance');});
$case('source deletion reappearing during unpublish rolls back',function()use($fixture,$source,$target,$assert,$expectFailure,$writes){[$s,$a,$st,$e]=$fixture();$source($s,1);$target(1);$p=$e->plan('fixture');$e->apply($p['run_id']);unset($s->rows[1]);$p=$e->plan('fixture');$a->duringWrite=function($id)use($s){$s->rows[$id]=['type'=>'fixture','payload'=>'REAPPEARED'];};$expectFailure(fn()=>$e->apply($p['run_id'],['missing_source'=>'unpublish']),'reappeared');$assert($writes()===0,'Reappeared source unpublished target');});
$case('unchanged batch avoids per-entity source rereads',function()use($fixture,$source,$target,$assert,$writes){[$s,$a,$st,$e]=$fixture();foreach(range(1,50)as$id){$source($s,$id);$target($id);}$p=$e->plan('fixture');$e->apply($p['run_id']);$p=$e->plan('fixture');$s->fingerprintCalls=0;$e->apply($p['run_id'],['batch_size'=>7]);$assert($s->fingerprintCalls===0&&$writes()===0,'Unchanged batch amplified fingerprint reads');});
$case('empty known source and mass deletion require independent explicit confirmation',function()use($db,$target,$assert,$expectFailure,$writes){$s=new class extends EngineFixtureSource{public function rootCount($type,$ceiling){return count($this->page($type,0,500,$ceiling));}};$a=new EngineFixtureAdapter($db,$s);$st=new DeltaStore($db);$e=new DeltaEngine($s,$a,$st,new NullLockBackend,hash('sha256','fixture-source'));foreach(range(1,12)as$id)$target($id);$p=$e->plan('fixture');$expectFailure(fn()=>$e->apply($p['run_id'],['missing_source'=>'unpublish','source_wins'=>TRUE]),'confirm-mass-unpublish');$assert($writes()===0,'Mass guard wrote first');$e->apply($p['run_id'],['missing_source'=>'unpublish','source_wins'=>TRUE,'confirm_mass_unpublish'=>TRUE]);$assert($writes()===12,'Confirmed mass unpublish failed');});
$case('membership count change during resumable scan rejects completed inventory',function()use($db,$source,$expectFailure){$s=new class extends EngineFixtureSource{public function rootCount($type,$ceiling){return count($this->page($type,0,500,$ceiling));}};$a=new EngineFixtureAdapter($db,$s);$st=new DeltaStore($db);$e=new DeltaEngine($s,$a,$st,new NullLockBackend,hash('sha256','fixture-source'));$source($s,1);$source($s,2);$p=$e->plan('fixture',['max_items'=>1]);unset($s->rows[2]);$expectFailure(fn()=>$e->plan('fixture',['run_id'=>$p['run_id']]),'membership');});
$case('manifest listing keeps array rows, pagination, filters and decoded details', function () use ($fixture, $assert) {
  [, , $store] = $fixture();
  $run = $store->createRun([
    'source_type' => 'fixture', 'bundle' => 'fixture', 'ceiling' => 3,
    'mapping_hash' => hash('sha256', 'mapping'),
    'source_identity' => hash('sha256', 'source'),
  ]);
  $store->plannedBatch($run, [
    ['source_id' => 3, 'operation' => 'deleted'],
    ['source_id' => 1, 'operation' => 'create', 'details' => ['marker' => 'first']],
    ['source_id' => 2, 'operation' => 'create'],
  ], ['phase' => 'ready']);
  $store->batchStatus($run['run_id'], [2], 'error');
  $rows = $store->listItems($run['run_id']);
  $assert(array_map('intval', array_column($rows, 'source_id')) === [1, 2, 3], 'Manifest ordering changed.');
  $assert($rows[0]['details'] === ['marker' => 'first'], 'Manifest details were not decoded.');
  $page = $store->listItems($run['run_id'], 1, 1);
  $assert(count($page) === 1 && (int) $page[0]['source_id'] === 2, 'Manifest pagination changed.');
  $errors = $store->listItems($run['run_id'], 0, 100, 'create', 'error');
  $assert(count($errors) === 1 && (int) $errors[0]['source_id'] === 2, 'Manifest filters changed.');
  foreach ([[-1, 1], [0, 0], [0, 501]] as [$after, $limit]) {
    try {
      $store->listItems($run['run_id'], $after, $limit);
      throw new LogicException('Invalid listing bounds were accepted.');
    }
    catch (InvalidArgumentException) {
      // Bounds must fail before listing any rows.
    }
  }
});
$case('pending dependencies keep newest array rows and isolate source types', function () use ($fixture, $assert) {
  [, , $store] = $fixture();
  $runs = [];
  foreach ([['fixture', 100, 41], ['fixture', 200, 42], ['other', 300, 43]] as [$type, $created, $destination]) {
    $run = $store->createRun([
      'source_type' => $type, 'bundle' => 'fixture', 'ceiling' => 7, 'created' => $created,
      'mapping_hash' => hash('sha256', 'mapping'),
      'source_identity' => hash('sha256', 'source'),
    ]);
    $store->plannedBatch($run, [
      ['source_id' => 7, 'destination_id' => $destination, 'operation' => 'create_staged'],
    ], ['phase' => 'ready']);
    $store->batchStatus($run['run_id'], [7], 'waiting_refs');
    $runs[] = $run;
  }
  $store->batchStatus($runs[1]['run_id'], [7], 'error');
  $pending = $store->pendingDependencies('fixture', [7]);
  $assert(count($pending) === 1 && (int) $pending[7]['destination_id'] === 42, 'Newest dependency was lost or another source type leaked.');
  $assert($pending[7]['run_id'] === $runs[1]['run_id'], 'Wrong dependency manifest selected.');
  $assert($store->pendingDependencies('fixture', []) === [] && $store->baselines('fixture', []) === [], 'Empty requests must stay empty.');
});
echo json_encode(['passed'=>count($passed),'cases'=>$passed],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}
finally {
  foreach (array_reverse($temporaryTables) as $table) {
    $db->schema()->dropTable($table);
  }
  Database::removeConnection($connectionKey);
  restore_error_handler();
}
