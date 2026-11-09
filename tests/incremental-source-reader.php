<?php

/** SELECT-only reader regressions against an isolated, prefixed MySQL fixture. */
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\labdoo_migrate\Services\Incremental\NodeSourceReader;

$db = Database::getConnection();
if (!Settings::get('labdoo_e2e') || !str_starts_with($db->getConnectionOptions()['database'], 'labdoo_e2e_')) {
  throw new RuntimeException('An isolated E2E database is required.');
}
require_once dirname(__DIR__) . '/web/modules/custom/labdoo_migrate/src/Services/Incremental/NodeSourceReader.php';
$key = 'source_reader_' . bin2hex(random_bytes(5));
$options = $db->getConnectionOptions();
$options['prefix'] = $key . '_';
Database::addConnectionInfo($key, 'default', $options);
$source = Database::getConnection('default', $key);
$tables = [];
$passed = [];
$start = microtime(TRUE);
$create = static function (string $table, array $columns) use ($source, &$tables): void {
  $source->schema()->createTable($table, ['fields' => $columns]);
  $tables[] = $table;
};
$integer = ['type' => 'int', 'not null' => TRUE, 'default' => 0];
$string = ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''];
$assert = static function (bool $condition, string $message): void {
  if (!$condition) throw new LogicException($message);
};
try {
  $create('node', array_fill_keys(['nid','vid','tnid','changed','status','uid','created'], $integer) + array_fill_keys(['type','language','title'], $string));
  $create('field_config_instance', array_fill_keys(['field_name','entity_type','bundle'], $string) + ['deleted' => $integer]);
  $common = array_fill_keys(['entity_id','revision_id','deleted','delta'], $integer) + array_fill_keys(['entity_type','bundle','language'], $string);
  $create('field_data_body', $common + ['body_value' => ['type' => 'text', 'size' => 'big'], 'body_format' => $string]);
  $create('field_data_field_picture', $common + ['field_picture_fid' => $integer]);
  $create('field_data_field_origin', $common + ['field_origin_lid' => $integer]);
  $create('field_collection_item', ['item_id'=>$integer,'revision_id'=>$integer,'field_name'=>$string]);
  $create('field_data_field_story_section', $common + ['field_story_section_value'=>$integer,'field_story_section_revision_id'=>$integer]);
  $create('field_data_field_story_heading', $common + ['field_story_heading_value'=>['type'=>'text','size'=>'big']]);
  $create('field_data_field_story_text', $common + ['field_story_text_value'=>['type'=>'text','size'=>'big'],'field_story_text_format'=>$string]);
  $create('field_data_field_story_picture', $common + ['field_story_picture_fid'=>$integer,'field_story_picture_alt'=>$string,'field_story_picture_title'=>$string]);
  $create('location_instance', array_fill_keys(['nid','vid','lid'], $integer));
  $create('location', ['lid' => $integer, 'country' => $string, 'city' => $string, 'latitude' => ['type' => 'numeric', 'precision' => 10, 'scale' => 6]]);
  $create('file_managed', array_fill_keys(['fid','filesize','status','timestamp'], $integer) + array_fill_keys(['uri','filename','filemime'], $string));
  foreach ([[1,10,1,'fixture','en'],[2,20,1,'fixture','es'],[3,30,0,'fixture','en'],[4,40,0,'other','en']] as [$nid,$vid,$tnid,$type,$language]) {
    $source->insert('node')->fields(['nid'=>$nid,'vid'=>$vid,'tnid'=>$tnid,'type'=>$type,'language'=>$language,'changed'=>100,'status'=>1,'uid'=>1,'created'=>50,'title'=>'Node '.$nid])->execute();
  }
  foreach (['body','field_picture'] as $field) $source->insert('field_config_instance')->fields(['field_name'=>$field,'entity_type'=>'node','bundle'=>'fixture','deleted'=>0])->execute();
  foreach ([1=>10,2=>20,3=>30] as $nid=>$vid) {
    $source->insert('field_data_body')->fields(['entity_id'=>$nid,'revision_id'=>$vid,'deleted'=>0,'delta'=>0,'entity_type'=>'node','bundle'=>'fixture','language'=>$nid===2?'es':'en','body_value'=>'Body '.$nid,'body_format'=>'filtered_html'])->execute();
  }
  $source->insert('field_data_field_picture')->fields(['entity_id'=>1,'revision_id'=>10,'deleted'=>0,'delta'=>0,'entity_type'=>'node','bundle'=>'fixture','language'=>'en','field_picture_fid'=>7])->execute();
  $source->insert('file_managed')->fields(['fid'=>7,'filesize'=>100,'status'=>1,'timestamp'=>10,'uri'=>'public://example.png','filename'=>'example.png','filemime'=>'image/png'])->execute();
  foreach ([[1,10,11],[1,9,12]] as [$nid,$vid,$lid]) $source->insert('location_instance')->fields(compact('nid','vid','lid'))->execute();
  foreach ([11=>'Current',12=>'Historical'] as $lid=>$city) $source->insert('location')->fields(['lid'=>$lid,'city'=>$city,'country'=>'es','latitude'=>'40.000000'])->execute();
  $mapping = ['source_type'=>'fixture','entity_type'=>'node','fields_mapping'=>[
    ['source'=>['fields'=>[['table'=>'node','field'=>'title','key'=>'nid']]]],
    ['source'=>['fields'=>[['table'=>'field_data_body','field'=>'body_value','key'=>'entity_id']]]],
    ['source'=>['fields'=>[['table'=>'field_data_field_picture','field'=>'field_picture_fid','key'=>'entity_id','join'=>['table'=>'file_managed','key_field'=>'fid','value_field'=>'uri']]]]],
    ['source'=>['fields'=>[['table'=>'location','field'=>'city','key'=>'node.nid']]]],
  ]];
  $log = Database::startLog('reader_select_only', $key);
  $reader = new NodeSourceReader($source, $mapping);
  $assert($reader->maxId('fixture')===3, 'Max ID must exclude translation and other types.');
  $assert(array_keys($reader->page('fixture',0,1,3))===[1] && array_keys($reader->page('fixture',1,1,3))===[3] && $reader->page('fixture',1,1,2)===[], 'Keyset/ceiling must exclude translations.');
  $assert($reader->existing([1,2,4,999])===[1=>'fixture',2=>'fixture',4=>'other'], 'Existing IDs must expose translations/retyping and omit absent IDs.');
  $passed[]='root-only bounded keyset, ceiling and existing/retyping';
  $hash = static fn(): string => $reader->fingerprints('fixture',[1])[1]['hash'];
  $base=$hash();
  $assert($base===$hash(), 'Unchanged source must be deterministic.');
  $snapshot=$reader->snapshots('fixture',[1]);
  $assert(count($snapshot['translations'][1])===2 && count($snapshot['locations'][1])===1 && $snapshot['locations'][1][0]['city']==='Current' && isset($snapshot['files'][7]), 'Raw snapshots must share current family, locations and files.');
  $passed[]='deterministic fingerprint and batched raw snapshot contract';
  $beforeLog=count($log->get('reader_select_only'));
  $reader->snapshots('fixture',[1]);
  $assert(count($log->get('reader_select_only'))===$beforeLog,'Adapter snapshot reuse must not add SQL.');
  $passed[]='latest batch snapshot reuse adds zero queries';
  foreach ($log->get('reader_select_only') as $entry) $assert(str_starts_with(ltrim($entry['query']), 'SELECT'), 'Reader executed a non-SELECT statement.');
  $passed[]='reader executes SELECT statements exclusively';
  $source->update('field_data_body')->fields(['body_value'=>'Changed without timestamp'])->condition('entity_id',2)->execute();
  $assert($hash()!==$base && $source->select('node','n')->fields('n',['changed'])->condition('nid',1)->execute()->fetchField()==100,'Translation field edit without changed must be detected.');
  $passed[]='translation field edit with unchanged parent timestamp';
  $changed=$hash();
  $source->update('field_data_body')->fields(['deleted'=>1])->condition('entity_id',2)->execute();
  $assert($hash()!==$changed,'Deleted current field row must change hash.');
  $source->delete('field_data_body')->condition('entity_id',2)->execute();
  $deleted=$hash();
  $assert($deleted===$hash(),'Deleted rows must behave as absent current rows.');
  $passed[]='current field removal/deleted=1 exclusion';
  $before=$hash();
  $source->update('file_managed')->fields(['uri'=>'public://renamed.png','filesize'=>101])->condition('fid',7)->execute();
  $assert($hash()!==$before,'Mapped file metadata change must be detected.');
  $passed[]='referenced file metadata URI/size changes';
  $before=$hash();
  $source->update('location')->fields(['city'=>'Historic edit'])->condition('lid',12)->execute();
  $assert($hash()===$before,'Historical location revision must not affect current hash.');
  $source->update('location')->fields(['latitude'=>'41.250000'])->condition('lid',11)->execute();
  $assert($hash()!==$before,'Current coordinates without node timestamp change must be detected.');
  $passed[]='only current location revision and coordinate changes';
  $before=$hash();
  $source->insert('node')->fields(['nid'=>5,'vid'=>50,'tnid'=>1,'type'=>'fixture','language'=>'de','changed'=>100,'status'=>1,'uid'=>1,'created'=>50,'title'=>'German'])->execute();
  $assert($hash()!==$before,'New translation must alter root hash.');
  $source->delete('node')->condition('nid',5)->execute();
  $assert($hash()===$before,'Removed translation must restore previous root hash.');
  $passed[]='translation addition/removal with unchanged root timestamp';
  $before=$hash();
  $source->getClientConnection()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, TRUE);
  $assert($hash()===$before,'PDO integer/string representations must hash identically.');
  $source->getClientConnection()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, FALSE);
  $passed[]='numeric types normalized across PDO fetch modes';
  $reader->refresh();
  $batchStart = count($log->get('reader_select_only'));
  $reader->fingerprints('fixture',[1,3]);
  $logs=$log->get('reader_select_only');
  // Fixture mutations are deliberately external to the reader, exclude those.
  $readLogs=array_values(array_filter($logs,static fn(array $entry):bool=>str_starts_with(ltrim($entry['query']),'SELECT')));
  $lastQueries=array_slice($logs,$batchStart);
  $assert(count($lastQueries)===8,'Expected exactly eight SELECTs for two-root batch.');
  foreach ($lastQueries as $entry) $assert(str_starts_with(ltrim($entry['query']),'SELECT'),'Batch wrote to source.');
  $passed[]='one query per dependency/table, no per-node field queries';
  foreach ([['ids',fn()=>$reader->existing([0])],['batch',fn()=>$reader->page('fixture',0,501,1000)],['unmapped',fn()=>$reader->maxId('missing')]] as [$label,$operation]) {
    try { $operation(); throw new LogicException('Expected failure:'.$label); }
    catch (InvalidArgumentException|RuntimeException $e) { $passed[]='fail closed: '.$label; }
  }
  $bad=$mapping;$bad['fields_mapping'][]=['source'=>['fields'=>[['table'=>'users_roles','field'=>'rid']]]];
  try { (new NodeSourceReader($source,$bad))->maxId('fixture'); throw new LogicException('Unsupported dependency accepted.'); }
  catch (RuntimeException $e) { $passed[]='fail closed: uncovered mapping dependency'; }
  // Scale probe: 500 roots, 1,000 current field rows and shared dependencies.
  $nodes = $source->insert('node')->fields(['nid','vid','tnid','type','language','changed','status','uid','created','title']);
  $bodies = $source->insert('field_data_body')->fields(['entity_id','revision_id','deleted','delta','entity_type','bundle','language','body_value','body_format']);
  $pictures = $source->insert('field_data_field_picture')->fields(['entity_id','revision_id','deleted','delta','entity_type','bundle','language','field_picture_fid']);
  $locations = $source->insert('location_instance')->fields(['nid','vid','lid']);
  $batchIds = range(1000,1499);
  foreach ($batchIds as $nid) {
    $nodes->values([$nid,$nid*10,0,'fixture','en',100,1,1,50,'Scale '.$nid]);
    $bodies->values([$nid,$nid*10,0,0,'node','fixture','en',str_repeat('bounded body ',25),'filtered_html']);
    $pictures->values([$nid,$nid*10,0,0,'node','fixture','en',7]);
    $locations->values([$nid,$nid*10,11]);
  }
  foreach ([$nodes,$bodies,$pictures,$locations] as $insert) $insert->execute();
  $batchLog = count($log->get('reader_select_only'));
  $batchTime = microtime(TRUE);
  $batchMemory = memory_get_usage(TRUE);
  $hashes = $reader->fingerprints('fixture',$batchIds);
  $scale = ['roots'=>count($hashes),'seconds'=>round(microtime(TRUE)-$batchTime,4),'selects'=>count($log->get('reader_select_only'))-$batchLog,'memory_growth_bytes'=>memory_get_usage(TRUE)-$batchMemory];
  $assert(count($hashes)===500 && $scale['selects']===8,'500 roots must still use exactly eight batched SELECTs.');
  $passed[]='500-root scale probe: constant eight SELECTs';
  // Exercise the real streaming budget without one oversized SQL packet.
  $source->update('field_data_body')->fields(['body_value'=>str_repeat('bounded ',5000)])->condition('entity_id',$batchIds,'IN')->execute();
  $bufferedBefore=$source->getClientConnection()->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
  try { $reader->fingerprints('fixture',$batchIds);throw new LogicException('Oversized source batch accepted.'); }
  catch(RuntimeException $e) { $assert(str_contains($e->getMessage(),'bounded reader capacity'),'Unexpected budget exception.'); }
  $assert($source->getClientConnection()->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)===$bufferedBefore,'PDO buffered mode must be restored after budget failure.');
  $source->update('field_data_body')->fields(['body_value'=>'Scale body restored'])->condition('entity_id',$batchIds,'IN')->execute();
  $passed[]='16MiB streaming budget fails closed and restores usable PDO mode';
  // Location fields may reference a current LID absent from location_instance.
  $source->insert('field_data_field_origin')->fields(['entity_id'=>1,'revision_id'=>10,'deleted'=>0,'delta'=>0,'entity_type'=>'node','bundle'=>'fixture','language'=>'en','field_origin_lid'=>13])->execute();
  $source->insert('location')->fields(['lid'=>13,'city'=>'Field origin','country'=>'es','latitude'=>'42.000000'])->execute();
  $locationMapping=$mapping;
  $locationMapping['fields_mapping'][3]['source']['fields'][0]['joins']=[['table'=>'field_data_field_origin','type'=>'INNER','condition'=>'location.lid = field_origin_lid']];
  $locationReader=new NodeSourceReader($source,$locationMapping);
  $before=$locationReader->fingerprints('fixture',[1])[1]['hash'];
  $locationSnapshot=$locationReader->snapshots('fixture',[1]);
  $assert(count($locationSnapshot['locations'][1])===2 && isset($locationSnapshot['fields']['field_data_field_origin'][1]),'Join field_data and field-only current LIDs must be loaded.');
  $source->update('location')->fields(['city'=>'Origin changed without node timestamp'])->condition('lid',13)->execute();
  $assert($locationReader->fingerprints('fixture',[1])[1]['hash']!==$before,'Field-only location edit must change root fingerprint.');
  $passed[]='current *_lid field joins and referenced location changes without instance';
  $source->update('field_data_field_origin')->fields(['field_origin_lid'=>999999])->condition('entity_id',1)->execute();
  try { $locationReader->fingerprints('fixture',[1]);throw new LogicException('Dangling current LID accepted.'); }
  catch(RuntimeException $e) { $passed[]='fail closed: dangling current location field'; }
  // A story is current node fields plus referenced field collection revisions.
  foreach ([[6,60,6,'en'],[7,70,6,'es']] as [$nid,$vid,$tnid,$language]) $source->insert('node')->fields(['nid'=>$nid,'vid'=>$vid,'tnid'=>$tnid,'type'=>'labdoo_story','language'=>$language,'changed'=>100,'status'=>1,'uid'=>1,'created'=>50,'title'=>'Story '.$nid])->execute();
  $source->insert('field_config_instance')->fields(['field_name'=>'field_story_section','entity_type'=>'node','bundle'=>'labdoo_story','deleted'=>0])->execute();
  foreach ([[6,60,0,81,91],[6,60,1,82,92],[7,70,0,83,93]] as [$nid,$nodeVid,$delta,$item,$sectionVid]) {
    $source->insert('field_data_field_story_section')->fields(['entity_id'=>$nid,'revision_id'=>$nodeVid,'deleted'=>0,'delta'=>$delta,'entity_type'=>'node','bundle'=>'labdoo_story','language'=>'und','field_story_section_value'=>$item,'field_story_section_revision_id'=>$sectionVid])->execute();
    $source->insert('field_collection_item')->fields(['item_id'=>$item,'revision_id'=>$sectionVid,'field_name'=>'field_story_section'])->execute();
    foreach (['heading','text','picture'] as $part) {
      $row=['entity_id'=>$item,'revision_id'=>$sectionVid,'deleted'=>0,'delta'=>0,'entity_type'=>'field_collection_item','bundle'=>'field_story_section','language'=>'und'];
      $row += match($part) {'heading'=>['field_story_heading_value'=>'Heading '.$item],'text'=>['field_story_text_value'=>'Text '.$item,'field_story_text_format'=>'filtered_html'],'picture'=>['field_story_picture_fid'=>7,'field_story_picture_alt'=>'Alt '.$item,'field_story_picture_title'=>'Picture '.$item]};
      $source->insert('field_data_field_story_'.$part)->fields($row)->execute();
    }
  }
  $storyMapping=['source_type'=>'labdoo_story','entity_type'=>'node','fields_mapping'=>[['source'=>['fields'=>[['table'=>'node','key'=>'nid','field'=>'title']]]]]];
  $storyReader=new NodeSourceReader($source,$storyMapping);
  $storyHash=static fn():string=>$storyReader->fingerprints('labdoo_story',[6])[6]['hash'];
  $before=$storyHash();$storySnapshot=$storyReader->snapshots('labdoo_story',[6]);
  $assert(count($storySnapshot['story_sections'][6])===2 && count($storySnapshot['story_sections'][7])===1 && $storySnapshot['story_sections'][6][0]['heading']['field_story_heading_value']==='Heading 81' && isset($storySnapshot['files'][7]),'Story snapshot section order, translations and picture metadata missing.');
  $source->update('field_data_field_story_text')->fields(['field_story_text_value'=>'Changed section without timestamp'])->condition('revision_id',91)->execute();
  $assert($storyHash()!==$before,'Section text changed without node timestamp must change root hash.');
  $passed[]='story field collections, ordered sections and section text without timestamp';
  $before=$storyHash();
  $source->update('field_data_field_story_heading')->fields(['field_story_heading_value'=>'Translated heading changed'])->condition('revision_id',93)->execute();
  $assert($storyHash()!==$before,'Translated section change must change original root fingerprint.');
  $passed[]='translated story section change fingerprints root';
  $before=$storyHash();
  $source->update('file_managed')->fields(['filesize'=>102])->condition('fid',7)->execute();
  $assert($storyHash()!==$before,'Story picture metadata change must change fingerprint.');
  $passed[]='story picture file metadata participates in fingerprint';
  $before=$storyHash();
  $source->update('field_data_field_story_heading')->fields(['deleted'=>1])->condition('revision_id',91)->execute();
  $assert($storyHash()!==$before && $storyReader->snapshots('labdoo_story',[6])['story_sections'][6][0]['heading']===NULL,'Deleted collection component must be absent and hash change.');
  $passed[]='deleted story component becomes NULL and changes fingerprint';
  $source->update('field_data_field_story_text')->fields(['entity_id'=>81])->condition('revision_id',92)->execute();
  try {$storyHash();throw new LogicException('Wrong collection entity identity accepted.');}catch(RuntimeException $e){$passed[]='fail closed: story VID belongs to another item';}
  $source->update('field_data_field_story_text')->fields(['entity_id'=>82])->condition('revision_id',92)->execute();
  $duplicate=['entity_id'=>83,'revision_id'=>93,'deleted'=>0,'delta'=>0,'entity_type'=>'field_collection_item','bundle'=>'field_story_section','language'=>'und','field_story_text_value'=>'Duplicate','field_story_text_format'=>'filtered_html'];
  $source->insert('field_data_field_story_text')->fields($duplicate)->execute();
  try {$storyHash();throw new LogicException('Duplicate collection component accepted.');}catch(RuntimeException $e){$passed[]='fail closed: duplicate story component';}
  $source->schema()->dropField('node','tnid');
  try { new NodeSourceReader($source,$mapping); throw new LogicException('Missing schema accepted.'); }
  catch (RuntimeException $e) { $passed[]='fail closed: unexpected source schema'; }
  echo json_encode(['passed'=>count($passed),'cases'=>$passed,'duration_seconds'=>round(microtime(TRUE)-$start,3),'peak_memory_bytes'=>memory_get_peak_usage(TRUE),'reader_selects'=>count($readLogs),'fixture_nodes'=>507,'scale_probe'=>$scale,'source_mutations'=>'fixture tables only, cleaned in finally'],JSON_PRETTY_PRINT).PHP_EOL;
}
finally {
  foreach(array_reverse($tables) as $table) $source->schema()->dropTable($table);
  Database::removeConnection($key);
}
