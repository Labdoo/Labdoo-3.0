<?php
/** Real paragraph/file regressions in the independent incremental fixture only. */
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use Drupal\labdoo_migrate\Services\Incremental\{ManagedFileIdentity,StorySectionAdapter};
$db=Database::getConnection();
if(!Settings::get('labdoo_e2e')||$db->getConnectionOptions()['database']!=='labdoo_e2e_incremental_dest_20261109'||!str_contains(DRUPAL_ROOT,'/.e2e-lab/incremental-site/'))throw new RuntimeException('Independent fixture required');
$assert=static function($c,$m){if(!$c)throw new LogicException($m);};$passed=[];$files=[];$transaction=$db->startTransaction();
$count=static function($table)use($db){return(int)$db->select($table,'t')->countQuery()->execute()->fetchField();};
$expectFailure=static function($fn,$text)use($assert){try{$fn();}catch(RuntimeException$e){$assert(str_contains($e->getMessage(),$text),'Unexpected error: '.$e->getMessage());return;}throw new LogicException('Expected failure '.$text);};
try{
$identity=new ManagedFileIdentity($db);$suffix=bin2hex(random_bytes(5));$uri='public://incremental-fixture-'.$suffix.'.txt';$files[]=$uri;file_put_contents($uri,"Synthetic fixture\n");
$source=['fid'=>555,'uri'=>$uri,'filename'=>'fixture.txt','filemime'=>'text/plain','filesize'=>18];
$before=$count('file_managed');$item=$identity->project($source);$assert(isset($item['@source_file'])&&$count('file_managed')===$before,'Projection wrote file metadata');$passed[]='unregistered synchronized binary projects virtually without writes';
$item=$identity->materialize($item);$assert($count('file_managed')===$before+1&&$identity->resolve(555,$uri)===$item['target_id'],'Materialization identity missing');$again=$identity->project($source);$assert($again===['target_id'=>$item['target_id']]&&$count('file_managed')===$before+1,'Repeat duplicated managed file');$passed[]='materialize existing binary once, reuse logical URI with different source FID';
$missing=$source;$missing['uri']='public://missing-'.$suffix.'.txt';$expectFailure(fn()=>$identity->materialize($identity->project($missing)),'Synchronize source public files');
$private=$source;$private['uri']='private://incremental-fixture-'.$suffix.'.txt';$expectFailure(fn()=>$identity->materialize($identity->project($private)),'Synchronize source public files');$passed[]='absent and untracked private binaries rejected before metadata creation';
$escape=$source;$escape['uri']='public://../settings.php';$expectFailure(fn()=>$identity->materialize($identity->project($escape)),'Synchronize source public files');$passed[]='public path traversal rejected before binary inspection';
$baduri='public://incremental-bad-'.$suffix.'.txt';$files[]=$baduri;file_put_contents($baduri,'Size mismatch');$bad=$source;$bad['fid']=556;$bad['uri']=$baduri;$bad['filesize']=1;$expectFailure(fn()=>$identity->materialize($identity->project($bad)),'size');$bad['filemime']='image/png';$expectFailure(fn()=>$identity->materialize($identity->project($bad)),'MIME');$assert($count('file_managed')===$before+1,'Rejected binary allocated metadata');$passed[]='binary size/MIME mismatches reject without allocating file entities';
$story=new StorySectionAdapter;$node=Node::create(['type'=>'labdoo_story','title'=>'Synthetic story','uid'=>0,'langcode'=>'en']);
$section=static fn($heading,$text)=>['field_story_heading'=>[['value'=>$heading]],'field_story_text'=>[['value'=>$text,'format'=>'basic_html']],'field_story_picture'=>[]];
$desired=[$section('First','Body one'),$section('Second','Body two')];$before=$count('paragraphs_item');$story->apply($node,$desired);$refs=$node->get('field_story_section')->getValue();$assert(count($refs)===2&&$count('paragraphs_item')===$before+2&&$story->equal($node,$desired),'Story sections not created');$passed[]='source story sections create real paragraphs with matching content';
$revisionCount=$count('paragraphs_item_revision');$story->apply($node,array_reverse($desired));$reordered=$node->get('field_story_section')->getValue();$assert($reordered===array_reverse($refs)&&$count('paragraphs_item_revision')===$revisionCount,'Reorder created paragraph revisions');$passed[]='reordered identical story sections retain paragraph IDs and revision IDs';
$changed=[$section('Changed','Changed body'),$desired[0]];$story->apply($node,$changed);$new=$node->get('field_story_section')->getValue();$assert($new[0]['target_id']===$reordered[0]['target_id']&&$new[0]['target_revision_id']!==$reordered[0]['target_revision_id']&&$new[1]===$reordered[1],'Paragraph update did not preserve stable identities');$assert(\Drupal::entityTypeManager()->getStorage('paragraph')->loadRevision($reordered[0]['target_revision_id'])!==NULL,'Old paragraph revision disappeared');$passed[]='changed story section adds revision while preserving original paragraph history';
$before=$count('paragraphs_item');$revisionCount=$count('paragraphs_item_revision');$story->apply($node,[$changed[0]]);$assert($count('paragraphs_item')===$before&&$count('paragraphs_item_revision')===$revisionCount,'Section removal deleted history or created revisions');$passed[]='removed story section detaches reference and retains paragraph history';
$log=Database::startLog('story_projection');$assert($story->equal($node,[$changed[0]]),'Final story projection differs');foreach($log->get('story_projection')as$q)$assert((bool)preg_match('/^(SELECT|SHOW|DESCRIBE)\b/i',ltrim($q['query'])),'Story equality wrote persistent cache');$passed[]='story equality performs read-only SQL without persistent cache writes';
echo json_encode(['passed'=>count($passed),'cases'=>$passed],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}finally{$transaction->rollBack();foreach($files as$uri)if(is_file($uri))unlink($uri);}
