<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Database\Connection;
use Drupal\user\Entity\User;

/** Explicit, create-only repair of missing D7 author dependencies; never syncs users. */
final class UserDependencyImporter {
  private const LOCK = 'labdoo_migrate.delta';
  private const PROFILE = ['field_about_me','field_user_name','field_notification_area','field_skills','field_hub_manager_notifications','field_picture'];

  public function __construct(private Connection $source, private Connection $destination) {
    if ($source === $destination || $destination !== \Drupal::database()) { throw new \InvalidArgumentException('Separate Drupal 7 source and active destination connections are required.'); }
    foreach (['uid','name','mail','pass','status','created','language','timezone'] as $column) {
      if (!$source->schema()->fieldExists('users', $column)) { throw new \RuntimeException('A Drupal 7 user source is required.'); }
    }
  }

  private function sourceIdentity(): string {
    $options = $this->source->getConnectionOptions();
    return hash('sha256', json_encode(array_intersect_key($options, array_flip(['driver','database','host','port','prefix'])), JSON_THROW_ON_ERROR));
  }

  /** No raw names, email addresses, profile text or password hashes leave this class. */
  private function snapshot(int $uid): ?array {
    $user = $this->source->select('users','u')->fields('u')->condition('uid',$uid)->execute()->fetchAssoc();
    if (!$user) { return NULL; }
    $roles = array_map('intval', $this->source->select('users_roles','r')->fields('r',['rid'])->condition('uid',$uid)->orderBy('rid')->execute()->fetchCol());
    $data = ['user'=>$user, 'roles'=>$roles, 'profile'=>[], 'locations'=>[], 'file'=>NULL];
    foreach (self::PROFILE as $field) {
      $table = 'field_data_' . $field;
      $data['profile'][$field] = $this->source->schema()->tableExists($table) ? $this->source->select($table,'f')->fields('f')->condition('entity_type','user')->condition('entity_id',$uid)->condition('deleted',0)->orderBy('language')->orderBy('delta')->execute()->fetchAll(\PDO::FETCH_ASSOC) : [];
    }
    if ($this->source->schema()->tableExists('location_instance') && $this->source->schema()->fieldExists('location_instance','uid')) {
      $query=$this->source->select('location_instance','i');$query->innerJoin('location','l','l.lid=i.lid');
      $data['locations']=$query->fields('l')->condition('i.uid',$uid)->orderBy('l.lid')->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    $pictures = $data['profile']['field_picture'];
    $fid = $pictures ? (int)($pictures[0]['field_picture_fid']??0) : (int)($user['picture']??0);
    if ($fid) { $data['file']=$this->source->select('file_managed','f')->fields('f')->condition('fid',$fid)->execute()->fetchAssoc() ?: ['fid'=>$fid]; }
    // Login/access counters can change while an account is used and are not imported.
    $canonical=$data;unset($canonical['user']['access'],$canonical['user']['login']);
    $data['hash']=hash('sha256',$this->sourceIdentity().json_encode($canonical,JSON_THROW_ON_ERROR));
    return $data;
  }

  private function identity(int $uid): array {
    $ids=[];
    if ($this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
      $ids=$this->destination->select('labdoo_migrate_tracking','t')->fields('t',['destination_id'])->condition('entity_type','user')->condition('source_id',$uid)->execute()->fetchCol();
    }
    if ($this->destination->schema()->tableExists('migrate_map_d7_user')) {
      $ids=array_merge($ids,$this->destination->select('migrate_map_d7_user','m')->fields('m',['destid1'])->condition('sourceid1',$uid)->execute()->fetchCol());
    }
    return array_values(array_unique(array_map('intval',$ids)));
  }

  private function preview(int $uid, ?array $snapshot): array {
    $result=['uid'=>$uid,'operation'=>'blocked','reasons'=>[],'legacy_empty_required_fields'=>[]];
    if (!$snapshot) { $result['reasons'][]='source_user_missing';return $result; }
    $result['source_hash']=$snapshot['hash'];
    $ids=$this->identity($uid);
    if ($ids) {
      if (count($ids)!==1 || !$ids[0]) { $result['reasons'][]='ambiguous_migration_identity';return $result; }
      $exists=(bool)$this->destination->select('users','u')->condition('uid',$ids[0])->countQuery()->execute()->fetchField();
      if (!$exists) { $result['reasons'][]='dangling_migration_identity';return $result; }
      $result['operation']='preserved_existing';$result['destination_uid']=$ids[0];return $result;
    }
    if ($this->destination->select('users','u')->condition('uid',$uid)->countQuery()->execute()->fetchField()) { $result['reasons'][]='uid_occupied_without_identity'; }
    $user=$snapshot['user'];
    if ($this->destination->select('users_field_data','u')->condition('name',$user['name'])->countQuery()->execute()->fetchField()) { $result['reasons'][]='username_collision'; }
    if ($user['mail']!=='' && $this->destination->query('SELECT COUNT(*) FROM {users_field_data} WHERE LOWER(TRIM(mail))=:mail',[':mail'=>strtolower(trim($user['mail']))])->fetchField()) { $result['reasons'][]='email_collision'; }
    if (array_diff($snapshot['roles'],[2])) { $result['reasons'][]='special_roles_require_explicit_user_migration'; }
    $password=(string)$user['pass'];$kind=$password;
    if (str_starts_with($kind,'U$')) { $kind=substr($kind,1); }
    $legacy=str_starts_with($kind,'$S$')||str_starts_with($kind,'$P$')||str_starts_with($kind,'$H$');
    if ($legacy && !\Drupal::moduleHandler()->moduleExists('phpass')) { $result['reasons'][]='legacy_password_support_missing'; }
    if (!$legacy && ($password!=='' || (int)$user['status']!==0) && (password_get_info($password)['algoName']??'unknown')==='unknown') { $result['reasons'][]='unsupported_password_hash'; }
    if (!$snapshot['profile']['field_notification_area']) { $result['legacy_empty_required_fields'][]='field_notification_area'; }
    foreach ($snapshot['profile'] as $rows) {
      if (count(array_unique(array_column($rows,'language')))>1) { $result['reasons'][]='multilingual_profile_requires_explicit_mapping'; }
    }
    if ($snapshot['file']) {
      $file=$snapshot['file'];
      if (empty($file['uri']) || !(new ManagedFileIdentity($this->destination))->resolve((int)$file['fid'],$file['uri'])) { $result['reasons'][]='profile_picture_dependency_missing'; }
    }
    $result['reasons']=array_values(array_unique($result['reasons']));
    if (!$result['reasons']) { $result['operation']='create'; }
    return $result;
  }

  private function values(int $uid, array $snapshot): array {
    $source=$snapshot['user'];
    $preferred=$source['language'] && $source['language']!=='und' ? $source['language'] : \Drupal::languageManager()->getDefaultLanguage()->getId();
    if (!\Drupal::languageManager()->getLanguage($preferred)) { throw new \RuntimeException('Source preferred language is not configured.'); }
    $values=['uid'=>$uid,'name'=>$source['name'],'mail'=>$source['mail'],'init'=>$source['init']??$source['mail'],'status'=>(int)$source['status'],'created'=>(int)$source['created'],'preferred_langcode'=>$preferred,'timezone'=>$source['timezone']?:NULL,'pass'=>['value'=>$source['pass'],'pre_hashed'=>TRUE]];
    foreach ($snapshot['profile'] as $field=>$rows) {
      if ($field==='field_picture') { continue; }
      $items=[];
      foreach ($rows as $row) {
        $value=$row[$field.'_value']??NULL;if($value===NULL||$value===''){continue;}
        $items[]=$field==='field_about_me' ? ['value'=>$value,'format'=>'basic_html'] : ['value'=>$value];
      }
      if ($items) { $values[$field]=$items; }
    }
    if ($snapshot['file']) {
      $fid=(new ManagedFileIdentity($this->destination))->resolve((int)$snapshot['file']['fid'],$snapshot['file']['uri']);
      if (!$fid) { throw new \RuntimeException('Profile picture identity changed.'); }
      $picture=$snapshot['profile']['field_picture'][0]??[];
      $values['user_picture']=[['target_id'=>$fid,'alt'=>$picture['field_picture_alt']??'','title'=>$picture['field_picture_title']??'']];
    }
    $points=[];
    foreach($snapshot['locations']as$location){
      $lat=$location['latitude']??0;$lon=$location['longitude']??0;
      if(!is_numeric($lat)||!is_numeric($lon)||abs((float)$lat)>90||abs((float)$lon)>180){throw new \RuntimeException('Invalid source user coordinates.');}
      if((float)$lat!==0.0||(float)$lon!==0.0){$points[]=['value'=>'POINT ('.(float)$lon.' '.(float)$lat.')'];}
    }
    if($points){$values['field_locations']=$points;}
    return $values;
  }

  private function nodeInvariants(): array {
    $out=$this->destination->query('SELECT (SELECT COUNT(*) FROM {node}) node_count,(SELECT MAX(nid) FROM {node}) max_nid,(SELECT COUNT(*) FROM {node_revision}) revision_count,(SELECT MAX(vid) FROM {node_revision}) max_vid')->fetchAssoc();
    $options=$this->destination->getConnectionOptions();
    $out['auto_increment']=$this->destination->query('SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=:database AND TABLE_NAME IN (:node,:revision) ORDER BY TABLE_NAME',[':database'=>$options['database'],':node'=>trim($this->destination->prefixTables('{node}'),'`'),':revision'=>trim($this->destination->prefixTables('{node_revision}'),'`')])->fetchAll(\PDO::FETCH_ASSOC);
    if($this->destination->schema()->tableExists('node__field_team_members')){$out['global_team_members']=$this->destination->select('node__field_team_members','f')->fields('f')->condition('entity_id',24)->orderBy('langcode')->orderBy('delta')->execute()->fetchAll(\PDO::FETCH_ASSOC);}
    return $out;
  }

  /**
   * Preview reports create candidates; apply performs final entity validation.
   * Only the explicitly listed absent UIDs can be created, never updated.
   */
  public function run(array $uids, bool $apply=FALSE): array {
    if(!$uids||count($uids)>100){throw new \InvalidArgumentException('Specify 1..100 explicit user IDs.');}
    foreach($uids as &$uid){$uid=filter_var($uid,FILTER_VALIDATE_INT,['options'=>['min_range'=>2,'max_range'=>4294967295]]);if($uid===FALSE){throw new \InvalidArgumentException('Import requires original source UIDs greater than one.');}}unset($uid);
    $uids=array_values(array_unique($uids));$out=['dry_run'=>!$apply,'source_identity'=>$this->sourceIdentity(),'created'=>0,'preserved'=>0,'blocked'=>0,'users'=>[]];
    $lock=\Drupal::lock();$state=\Drupal::state();$sentinel=new \stdClass();$previous=$state->get('labdoo_migrate_is_running',$sentinel);$ownerExists=array_key_exists('labdoo_incremental_lock_owner',$GLOBALS);$previousOwner=$GLOBALS['labdoo_incremental_lock_owner']??NULL;
    if($apply&&($state->get('labdoo_migrate_is_running',FALSE)||$previousOwner)){throw new \RuntimeException('A migration writer is already active.');}
    if($apply&&!$lock->acquire(self::LOCK,300)){throw new \RuntimeException('Another incremental writer holds the global lock.');}
    try{
      if($apply){$GLOBALS['labdoo_incremental_lock_owner']=TRUE;}
      foreach($uids as$uid){
        $snapshot=$this->snapshot($uid);$result=$this->preview($uid,$snapshot);
        if($result['operation']==='create'&&$apply){
          $transaction=$this->destination->startTransaction();$switcher=\Drupal::service('account_switcher');$switched=FALSE;
          try{
            if(!$lock->acquire(self::LOCK,300)){throw new \RuntimeException('Migration lock lease lost.');}
            $fresh=$this->snapshot($uid);if(!$fresh||!hash_equals($snapshot['hash'],$fresh['hash'])){throw new \RuntimeException('Source changed before import.');}
            $again=$this->preview($uid,$fresh);if($again['operation']!=='create'){throw new \RuntimeException('Destination identity changed before import.');}
            $before=$this->nodeInvariants();$admin=ReadOnlyEntityLoader::storage('user')->load(1);if(!$admin){throw new \RuntimeException('Migration administrator is missing.');}
            $switcher->switchTo($admin);$switched=TRUE;$state->set('labdoo_migrate_is_running',TRUE);
            $account=User::create($this->values($uid,$fresh));$account->setSyncing(TRUE);$account->labdoo_skip_geocoding=TRUE;$account->skip_action_generation=TRUE;$account->skip_geocoding_enqueue=TRUE;$account->skip_recompute_enqueue=TRUE;$account->search_api_skip_tracking=TRUE;
            $errors=[];
            foreach($account->validate()as$violation){
              $path=$violation->getPropertyPath();$constraint=$violation->getConstraint();
              if(!$fresh['profile']['field_notification_area']&&$path==='field_notification_area'&&$constraint instanceof \Symfony\Component\Validator\Constraints\NotNull){continue;}
              $errors[]=$path;
            }
            if($errors){throw new \RuntimeException('Source account fails destination validation: '.implode(', ',array_unique($errors)));}
            $account->save();
            $stored=$this->destination->select('users_field_data','u')->fields('u',['pass'])->condition('uid',$uid)->condition('default_langcode',1)->execute()->fetchField();
            if(!is_string($stored)||!hash_equals((string)$fresh['user']['pass'],$stored)){throw new \RuntimeException('Imported password was unexpectedly transformed.');}
            $afterSource=$this->snapshot($uid);if(!$afterSource||!hash_equals($fresh['hash'],$afterSource['hash'])){throw new \RuntimeException('Source changed during import.');}
            if($before!==$this->nodeInvariants()){throw new \RuntimeException('User import changed node IDs, revisions or Global team membership.');}
            $this->destination->merge('labdoo_migrate_tracking')->keys(['entity_type'=>'user','bundle'=>'user','source_id'=>$uid])->fields(['destination_id'=>$uid,'last_migrated'=>time(),'duration_ms'=>0])->execute();
            $result['operation']='created';$result['destination_uid']=$uid;unset($transaction);
          }
          catch(\Throwable$e){$transaction->rollBack();\Drupal::service('entity.memory_cache')->deleteAll();throw new \RuntimeException('User dependency '.$uid.' was not imported: '.(get_class($e)===\RuntimeException::class?$e->getMessage():get_class($e)));}
          finally{if($switched){$switcher->switchBack();}if($previous===$sentinel){$state->delete('labdoo_migrate_is_running');}else{$state->set('labdoo_migrate_is_running',$previous);}}
        }
        if($result['operation']==='created'){$out['created']++;}elseif($result['operation']==='preserved_existing'){$out['preserved']++;}elseif($result['operation']==='blocked'){$out['blocked']++;}
        $out['users'][$uid]=$result;
      }
    }
    catch(\Throwable $e){
      // Preview queries also carry private names/email in bound parameters.
      if($e instanceof \Drupal\Core\Database\DatabaseExceptionWrapper || $e instanceof \PDOException){throw new \RuntimeException('User dependency database operation failed: '.get_class($e));}
      throw $e;
    }
    finally{if($apply){if($previous===$sentinel){$state->delete('labdoo_migrate_is_running');}else{$state->set('labdoo_migrate_is_running',$previous);}if($ownerExists){$GLOBALS['labdoo_incremental_lock_owner']=$previousOwner;}else{unset($GLOBALS['labdoo_incremental_lock_owner']);}$lock->release(self::LOCK);}}
    return $out;
  }
}
