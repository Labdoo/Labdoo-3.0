<?php
/** Explicit user dependency repair against SELECT-only source and isolated destination. */
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\node\Entity\Node;
use Drupal\phpass\Password\PhpassHashedPassword;
use Drupal\Core\Password\PhpPassword;
use Drupal\labdoo_migrate\Services\Incremental\UserDependencyImporter;
$db=Database::getConnection();
if(!Settings::get('labdoo_e2e')||$db->getConnectionOptions()['database']!=='labdoo_e2e_incremental_dest_20261109'){throw new RuntimeException('Independent fixture required.');}
$lab=dirname(__DIR__,3);$options=json_decode(file_get_contents($lab.'/state/incremental-source-writer-connection.json'),TRUE,512,JSON_THROW_ON_ERROR);
if($options['database']!=='labdoo_e2e_incremental_source_20261109'){throw new RuntimeException('Unsafe fixture source.');}
Database::addConnectionInfo('user_fixture_writer','default',$options);$writer=Database::getConnection('default','user_fixture_writer');$source=Database::getConnection('default','external');
$integer=['type'=>'int','not null'=>TRUE,'default'=>0];$string=['type'=>'varchar','length'=>255,'not null'=>TRUE,'default'=>''];
$profile=['field_about_me','field_user_name','field_notification_area','field_skills','field_hub_manager_notifications','field_picture'];
$schemas=['users'=>['fields'=>array_fill_keys(['uid','status','created','access','login','picture'],$integer)+array_fill_keys(['name','mail','init','pass','language','timezone'],$string),'primary key'=>['uid']],'users_roles'=>['fields'=>['uid'=>$integer,'rid'=>$integer]]];
foreach($profile as$field){$schemas['field_data_'.$field]=['fields'=>array_fill_keys(['entity_id','revision_id','deleted','delta'],$integer)+array_fill_keys(['entity_type','bundle','language',$field.($field==='field_picture'?'_fid':'_value')],$string)];}
$created=[];$ids=range(700001,700010);$passed=[];
$assert=static function(bool$ok,string$message):void{if(!$ok)throw new LogicException($message);};
foreach($schemas as$table=>$definition){if(!$writer->schema()->tableExists($table)){$writer->schema()->createTable($table,$definition);$created[]=$table;}}
$assert(!(bool)$writer->select('users','u')->condition('uid',$ids,'IN')->countQuery()->execute()->fetchField(),'Source fixture IDs occupied.');
$assert(!(bool)$db->select('users','u')->condition('uid',$ids,'IN')->countQuery()->execute()->fetchField(),'Destination fixture IDs occupied.');
$prior=\Drupal::state()->get('labdoo_migrate_is_running',NULL);$transaction=$db->startTransaction();$container=\Drupal::getContainer();$mail=$container->get('plugin.manager.mail');$spy=new class{public int$count=0;public function mail(...$args){$this->count++;return['result'=>TRUE];}};$container->set('plugin.manager.mail',$spy);
$counts=static function()use($db){$r=$db->query('SELECT (SELECT COUNT(*) FROM {node}) n,(SELECT MAX(nid) FROM {node}) nid,(SELECT COUNT(*) FROM {node_revision}) r,(SELECT MAX(vid) FROM {node_revision}) vid,(SELECT COUNT(*) FROM {queue}) q')->fetchAssoc();$r['team']=$db->select('node__field_team_members','f')->fields('f')->condition('entity_id',24)->orderBy('delta')->execute()->fetchAll(PDO::FETCH_ASSOC);return$r;};
$hash=(new ReflectionMethod(PhpassHashedPassword::class,'crypt'))->invoke(new PhpassHashedPassword(new PhpPassword()),'sha512','known-fixture-password','$S$9abcdefgh');
$sourceUser=static function(int$uid,array$changes=[])use($writer,$hash){$values=['uid'=>$uid,'name'=>'dep-fixture-'.$uid,'mail'=>'dep-'.$uid.'@example.invalid','init'=>'dep-'.$uid.'@example.invalid','pass'=>$hash,'status'=>1,'created'=>100,'access'=>200,'login'=>150,'language'=>'en','timezone'=>'UTC','picture'=>0];$writer->insert('users')->fields(array_replace($values,$changes))->execute();$writer->insert('field_data_field_user_name')->fields(['entity_type'=>'user','bundle'=>'user','deleted'=>0,'entity_id'=>$uid,'revision_id'=>0,'language'=>'und','delta'=>0,'field_user_name_value'=>'Dependency fixture'])->execute();};
try{
 \Drupal::state()->set('labdoo_migrate_is_running',TRUE);
 if(!User::load(1)){$admin=User::create(['uid'=>1,'name'=>'dependency_fixture_admin','status'=>1]);$admin->setSyncing(TRUE);$admin->save();}
 if(!Node::load(24)){$team=Node::create(['type'=>'team','nid'=>24,'title'=>'Dependency Global team fixture','uid'=>0]);$team->original_entity_id=24;$team->skip_action_generation=TRUE;$team->skip_geocoding_enqueue=TRUE;$team->skip_recompute_enqueue=TRUE;$team->save();}
 \Drupal::state()->delete('labdoo_migrate_is_running');$before=$counts();$accountBefore=\Drupal::currentUser()->id();
 $sourceUser(700001);$importer=new UserDependencyImporter($source,$db);
 $db->insert('semaphore')->fields(['name'=>'labdoo_migrate.delta','value'=>'fixture-expired-owner','expire'=>microtime(TRUE)-3600])->execute();
 $expiredBefore=$db->select('semaphore','s')->fields('s')->condition('name','labdoo_migrate.delta')->execute()->fetchAssoc();
 $log=Database::startLog('user_dependency_preview');$sourceLog=Database::startLog('user_dependency_source','external');
 $preview=$importer->run([700001]);$assert($preview['dry_run']&&$preview['users'][700001]['operation']==='create'&&$counts()===$before,'Preview changed content or failed candidate.');
 foreach(array_merge($log->get('user_dependency_preview'),$sourceLog->get('user_dependency_source'))as$query){$assert((bool)preg_match('/^(SELECT|SHOW|DESCRIBE)\b/i',ltrim($query['query'])),'Preview issued writable SQL.');}
 $passed[]='default preview issues SELECT-only SQL and reports a candidate without content writes';
 $assert($db->select('semaphore','s')->fields('s')->condition('name','labdoo_migrate.delta')->execute()->fetchAssoc()===$expiredBefore,'Preview removed an expired semaphore.');
 $passed[]='preview leaves an expired global semaphore unchanged';
 $assert($preview['users'][700001]['legacy_empty_required_fields']===['field_notification_area'],'Missing legacy preference was not reported.');$passed[]='legacy empty required notification radius is reported rather than fabricated';
 $result=$importer->run([700001],TRUE);$assert($result['created']===1&&$result['users'][700001]['destination_uid']===700001,'Create did not preserve source UID.');
 $stored=$db->select('users_field_data','u')->fields('u',['pass'])->condition('uid',700001)->execute()->fetchField();
 $assert(hash_equals($hash,$stored)&&\Drupal::service('password')->check('known-fixture-password',$stored),'Legacy password was rehashed or authentication failed.');
 $assert($counts()===$before&&$spy->count===0,'User import changed nodes, Global team, queues or sent mail.');$passed[]='explicit import preserves UID and D7 password authentication without node/team/queue/mail writes';
 $assert(\Drupal::currentUser()->id()===$accountBefore&&!\Drupal::state()->get('labdoo_migrate_is_running',FALSE)&&empty($GLOBALS['labdoo_incremental_lock_owner']),'Account, state or owner scope leaked.');$passed[]='migration state, current account and lock ownership restore after success';
 $assert($db->select('labdoo_migrate_tracking','t')->fields('t',['destination_id'])->condition('entity_type','user')->condition('source_id',700001)->execute()->fetchField()==700001,'User identity tracker missing.');$repeat=$importer->run([700001],TRUE);$assert($repeat['created']===0&&$repeat['preserved']===1&&$counts()===$before,'Repeated import updated existing identity.');$passed[]='tracker identity makes repeated imports preserve existing users without writes';
 $sourceUser(700002,['name'=>'dep-fixture-700001']);$collision=$importer->run([700002],TRUE);$assert($collision['blocked']===1&&in_array('username_collision',$collision['users'][700002]['reasons'],TRUE),'Username collision not blocked.');$passed[]='name collision blocks creating a second account';
 $sourceUser(700003,['mail'=>'DEP-700001@EXAMPLE.INVALID']);$collision=$importer->run([700003],TRUE);$assert($collision['blocked']===1&&in_array('email_collision',$collision['users'][700003]['reasons'],TRUE),'Email casefold collision not blocked.');$passed[]='email collision is case-insensitive and never merges accounts';
 $sourceUser(700004);$writer->insert('users_roles')->fields(['uid'=>700004,'rid'=>3])->execute();$blocked=$importer->run([700004],TRUE);$assert($blocked['blocked']===1&&in_array('special_roles_require_explicit_user_migration',$blocked['users'][700004]['reasons'],TRUE),'Administrator source role imported.');$passed[]='elevated roles require separate explicit user migration';
 $sourceUser(700006);foreach([5,6]as$rid){$writer->insert('users_roles')->fields(['uid'=>700006,'rid'=>$rid])->execute();}$default=$importer->run([700006]);$assert($default['blocked']===1&&$default['users'][700006]['mapped_roles']===['edoovillage_manager','hub_manager'],'Default role policy or preview mapping changed.');$previewRoles=$importer->run([700006],FALSE,TRUE);$assert($previewRoles['users'][700006]['operation']==='create'&&$previewRoles['users'][700006]['mapped_roles']===['edoovillage_manager','hub_manager'],'Explicit nonadmin source roles were not candidates.');$importedRoles=$importer->run([700006],TRUE,TRUE);$roleIds=$db->select('user__roles','r')->fields('r',['roles_target_id'])->condition('entity_id',700006)->execute()->fetchCol();sort($roleIds);$assert($importedRoles['created']===1&&$roleIds===['edoovillage_manager','hub_manager'],'Explicit source roles were not preserved exactly.');$passed[]='source-roles flag explicitly restores only existing mapped nonadmin roles; default still blocks';
 $admin=$importer->run([700004],TRUE,TRUE);$assert($admin['blocked']===1&&in_array('administrator_source_role_forbidden',$admin['users'][700004]['reasons'],TRUE),'Source administrator was imported by explicit role flag.');$sourceUser(700007);$writer->insert('users_roles')->fields(['uid'=>700007,'rid'=>99])->execute();$unknown=$importer->run([700007],TRUE,TRUE);$assert($unknown['blocked']===1&&in_array('unknown_source_role',$unknown['users'][700007]['reasons'],TRUE),'Unknown source role was imported.');$passed[]='source-roles flag never imports administrator or unknown source roles';
 $sourceUser(700008);$writer->insert('users_roles')->fields(['uid'=>700008,'rid'=>4])->execute();$role=Role::load('wiki_writer');$oldAdmin=$role->isAdmin();$role->setIsAdmin(TRUE)->save();try{$unsafe=$importer->run([700008],TRUE,TRUE);$assert($unsafe['blocked']===1&&in_array('destination_role_missing_or_administrative',$unsafe['users'][700008]['reasons'],TRUE),'Administrative destination role was granted.');}finally{$role->setIsAdmin($oldAdmin)->save();}$passed[]='mapped destination roles marked administrative are refused even with explicit source role permission';
 $sourceUser(700005);$writer->delete('field_data_field_user_name')->condition('entity_id',700005)->execute();$failed=FALSE;try{$importer->run([700005],TRUE);}catch(RuntimeException$e){$failed=TRUE;}$assert($failed&&!$db->select('users','u')->condition('uid',700005)->countQuery()->execute()->fetchField(),'Invalid user was not rolled back.');$assert($counts()===$before&&\Drupal::currentUser()->id()===$accountBefore&&!\Drupal::state()->get('labdoo_migrate_is_running',FALSE)&&empty($GLOBALS['labdoo_incremental_lock_owner']),'Failed import leaked content or migration scope.');$passed[]='other required fields still fail validation with transaction rollback and scope restoration';
 $lock=\Drupal::lock();$assert($lock->acquire('labdoo_migrate.delta',300),'Lock leaked after failure.');$lock->release('labdoo_migrate.delta');$passed[]='global incremental lock releases after failure';
 $writer->update('users')->fields(['name'=>'changed-source-name'])->condition('uid',700001)->execute();$repeat=$importer->run([700001],TRUE);$assert($repeat['preserved']===1&&$db->select('users_field_data','u')->fields('u',['name'])->condition('uid',700001)->execute()->fetchField()==='dep-fixture-700001','Existing account was overwritten by source changes.');$passed[]='existing accounts remain untouched when source account details later change';
 foreach($sourceLog->get('user_dependency_source')as$query){$assert((bool)preg_match('/^(SELECT|SHOW|DESCRIBE)\b/i',ltrim($query['query'])),'Importer wrote to D7 source.');}$passed[]='every source query remains read-only during explicit apply and failures';
 echo json_encode(['passed'=>count($passed),'cases'=>$passed],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}
finally{
 $container->set('plugin.manager.mail',$mail);$transaction->rollBack();\Drupal::service('entity.memory_cache')->deleteAll();
 if($prior===NULL){\Drupal::state()->delete('labdoo_migrate_is_running');}else{\Drupal::state()->set('labdoo_migrate_is_running',$prior);}
 foreach($profile as$field){$writer->delete('field_data_'.$field)->condition('entity_type','user')->condition('entity_id',$ids,'IN')->execute();}
 $writer->delete('users_roles')->condition('uid',$ids,'IN')->execute();$writer->delete('users')->condition('uid',$ids,'IN')->execute();foreach(array_reverse($created)as$table){$writer->schema()->dropTable($table);}
}
