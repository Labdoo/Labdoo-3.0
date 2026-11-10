<?php
/** Legacy shared-field assembly regression; uses dry-run repository, never saves. */
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use Drupal\labdoo_migrate\Model\{MappingModel,FieldModel,SpecialTypeModel};
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepository;
if(!Settings::get('labdoo_e2e')||!str_starts_with(Database::getConnection()->getConnectionOptions()['database'],'labdoo_e2e_'))throw new RuntimeException('Isolated E2E required');
require_once dirname(__DIR__).'/web/modules/custom/labdoo_migrate/src/Services/SpecialFieldTypes/SpecialFieldTypeAddressComponent.php';
$repository=(new ReflectionClass(DestinationRepository::class))->newInstanceWithoutConstructor();$mapping=[];
foreach(['country'=>'country_code','city'=>'locality','postal'=>'postal_code','street'=>'address_line1']as$key=>$component)$mapping[$key]=new MappingModel([new FieldModel('location','nid',$key)],new FieldModel('','','field_address',specialType:new SpecialTypeModel('address_component',['component'=>$component])));
(new ReflectionProperty($repository,'mapping'))->setValue($repository,$mapping);(new ReflectionProperty($repository,'dryRun'))->setValue($repository,TRUE);$update=new ReflectionMethod($repository,'updateEntity');$node=Node::create(['type'=>'action','langcode'=>'en']);
$assert=static function($c,$m){if(!$c)throw new LogicException($m);};
$update->invoke($repository,['country'=>'es','city'=>'Madrid','postal'=>'','street'=>NULL],$node,'en');$address=$node->get('field_address')->first()->getValue();$assert($address['country_code']==='ES'&&$address['locality']==='Madrid'&&$address['postal_code']===''&&$address['address_line1']==='','Empty components erased assembled country/city');
$update->invoke($repository,['country'=>'','city'=>NULL,'postal'=>'','street'=>''],$node,'en');$node->get('field_address')->filterEmptyItems();$assert($node->get('field_address')->isEmpty()&&$node->get('field_address')->count()===0&&$node->isNew()&&!$node->id(),'All-empty source did not normalize or repository saved a node');
echo json_encode(['passed'=>2,'cases'=>['legacy real accumulator preserves country/city with empty street/postal','all-empty legacy address normalizes without any node save']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
