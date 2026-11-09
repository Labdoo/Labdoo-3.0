<?php
/** CLI entry points must not clean an expired writer lock during previews. */
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\labdoo_migrate\Commands\IncrementalCommands;
use Symfony\Component\Console\Output\BufferedOutput;
$db=Database::getConnection();
if(!Settings::get('labdoo_e2e')||$db->getConnectionOptions()['database']!=='labdoo_e2e_incremental_dest_20261109')throw new RuntimeException('Independent fixture required');
$lab=dirname(__DIR__,3);$options=json_decode(file_get_contents($lab.'/state/incremental-source-writer-connection.json'),TRUE,512,JSON_THROW_ON_ERROR);
if($options['database']!=='labdoo_e2e_incremental_source_20261109')throw new RuntimeException('Unsafe source fixture');
Database::addConnectionInfo('cli_fixture_writer','default',$options);$writer=Database::getConnection('default','cli_fixture_writer');
$created=FALSE;if(!$writer->schema()->tableExists('users')){$integer=['type'=>'int','not null'=>TRUE,'default'=>0];$string=['type'=>'varchar','length'=>255,'not null'=>TRUE,'default'=>''];$writer->schema()->createTable('users',['fields'=>array_fill_keys(['uid','status','created'],$integer)+array_fill_keys(['name','mail','pass','language','timezone'],$string),'primary key'=>['uid']]);$created=TRUE;}
$tx=$db->startTransaction();$passed=[];$assert=static function($c,$m){if(!$c)throw new LogicException($m);};
try{
$assert(!$db->select('semaphore','s')->fields('s',['name'])->condition('name','labdoo_migrate.delta')->execute()->fetchField(),'Fixture writer lock already exists; do not overwrite it');
$db->insert('semaphore')->fields(['name'=>'labdoo_migrate.delta','value'=>'expired-fixture-writer','expire'=>microtime(TRUE)-3600])->execute();
$cli=new IncrementalCommands($db,\Drupal::service('labdoo_migrate.database.external_connection_manager'),\Drupal::lock());$buffer=new BufferedOutput;$cli->setOutput($buffer);
foreach(['nodes','users']as$kind){$log=Database::startLog('readonly_cli_'.$kind);$sourceLog=Database::startLog('readonly_cli_source_'.$kind,'external');$active=Database::getConnection()->getKey();
if($kind==='nodes')$cli->preview('team_page',['batch-size'=>2,'max-items'=>2]);else$cli->importUsers('4294967294',['apply'=>FALSE]);
$result=json_decode($buffer->fetch(),TRUE,512,JSON_THROW_ON_ERROR);$assert($kind==='nodes'?($result['read_only']??FALSE):($result['dry_run']??FALSE),'CLI did not report preview');
$assert(Database::getConnection()->getKey()===$active,'CLI leaked active external connection');$value=$db->select('semaphore','s')->fields('s',['value'])->condition('name','labdoo_migrate.delta')->execute()->fetchField();$assert($value==='expired-fixture-writer','Preview deleted or replaced expired writer lock');
foreach(array_merge($log->get('readonly_cli_'.$kind),$sourceLog->get('readonly_cli_source_'.$kind))as$query)$assert((bool)preg_match('/^(SELECT|SHOW|DESCRIBE)\b/i',ltrim($query['query'])),'CLI preview issued non-readonly SQL: '.strtok($query['query'],' '));
$passed[]=$kind.' CLI preview preserves expired semaphore and uses read-only SQL';}
echo json_encode(['passed'=>count($passed),'cases'=>$passed],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}finally{$tx->rollBack();if($created)$writer->schema()->dropTable('users');}
