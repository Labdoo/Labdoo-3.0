<?php
/** Portable menu fallback: in-memory entities only, no fixture IDs or writes. */
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\Core\Language\{Language,LanguageDefault,LanguageInterface,LanguageManager};
use Drupal\menu_link_content\Entity\MenuLinkContent as MenuEntity;
use Drupal\menu_link_content\Plugin\Menu\MenuLinkContent as CoreMenuPlugin;
use Drupal\labdoo_translations\Plugin\Menu\CatalogMenuLinkContent;

$db=Database::getConnection();
if(!Settings::get('labdoo_e2e')||!str_starts_with($db->getConnectionOptions()['database'],'labdoo_e2e_'))throw new RuntimeException('An isolated E2E bootstrap is required');
require_once dirname(__DIR__).'/web/modules/custom/labdoo_translations/src/Plugin/Menu/CatalogMenuLinkContent.php';
require_once dirname(__DIR__).'/web/modules/custom/labdoo_translations/labdoo_translations.module';
require_once dirname(__DIR__).'/web/modules/custom/labdoo_translations/src/CatalogMenuLinkManager.php';
require_once dirname(__DIR__).'/web/modules/custom/labdoo_translations/src/LabdooTranslationsServiceProvider.php';
$manager=new class(new LanguageDefault(['id'=>'en'])) extends LanguageManager {
  public string $current='es';
  public function getCurrentLanguage($type=LanguageInterface::TYPE_INTERFACE){return new Language(['id'=>$this->current]);}
  public function isMultilingual(){return TRUE;}
};
$catalog=new class {
  public array $calls=[];
  public function lookup(string$lang,string$source,string$context=''):?string {
    $this->calls[]=[$lang,$source,$context];
    return ['es'=>['Schools'=>'Escuelas','Find schools'=>'Buscar escuelas'],'fr'=>['Schools'=>'Écoles du catalogue','Find schools'=>'Trouver des écoles'],'ar'=>['Schools'=>'المدارس'],'de'=>['Schools'=>'Schulen','Find schools'=>'Schulen finden']][$lang][$source]??NULL;
  }
};
$container=\Drupal::getContainer();$had=$container->has('labdoo_translations.catalog');$previous=$had?$container->get('labdoo_translations.catalog'):NULL;$container->set('labdoo_translations.catalog',$catalog);
$assert=static function($c,$m){if(!$c)throw new LogicException($m);};$passed=[];
$make=static function(string$uuid,string$baseLanguage='es')use($manager){
  $entity=MenuEntity::create(['uuid'=>$uuid,'langcode'=>$baseLanguage,'title'=>'Schools','description'=>'Find schools','link'=>['uri'=>'internal:/schools'],'menu_name'=>'main','enabled'=>1]);
  // This portal disables native menu translations. Exercise a portable future
  // environment using private field-definition clones, without config writes.
  $fields=$entity->getFieldDefinitions();foreach(['title','description']as$field){$fields[$field]=clone $fields[$field];$fields[$field]->setTranslatable(TRUE);}
  (new ReflectionProperty(\Drupal\Core\Entity\ContentEntityBase::class,'fieldDefinitions'))->setValue($entity,$fields);
  $definition=['id'=>'menu_link_content:'.$uuid,'class'=>CoreMenuPlugin::class,'title'=>'Stale definition title','description'=>'Stale definition description','menu_name'=>'main','parent'=>'menu_link_content:parent-portable','route_name'=>'entity.node.canonical','route_parameters'=>['node'=>42],'options'=>['attributes'=>['data-example'=>'preserved']],'weight'=>7,'expanded'=>TRUE,'enabled'=>TRUE,'provider'=>'menu_link_content'];
  $plugin=new CatalogMenuLinkContent([],'menu_link_content:'.$uuid,$definition,\Drupal::entityTypeManager(),$manager,\Drupal::service('entity.repository'));
  (new ReflectionProperty(CoreMenuPlugin::class,'entity'))->setValue($plugin,$entity);
  return [$plugin,$entity,$definition];
};
try{
[$plugin,$entity,$definition]=$make('11111111-1111-4111-8111-111111111111');
$entity->addTranslation('fr',['title'=>'Écoles natives','description'=>'Find schools']);
$entity->addTranslation('de',['title'=>'Schools','description'=>'Find schools']);
$before=$entity->getUntranslated()->toArray();$beforeFrench=$entity->getTranslation('fr')->toArray();
// All entity/field setup above is local memory. The label path below must be pure.
$log=Database::startLog('portable_menu_lookup');
$manager->current='es';$assert($plugin->getTitle()==='Escuelas'&&$plugin->getDescription()==='Buscar escuelas','Wrong base langcode prevented English-source fallback');$passed[]='actual English base text translates despite incorrect Spanish entity langcode';
$manager->current='fr';$assert($plugin->getTitle()==='Écoles natives'&&$plugin->getDescription()==='Trouver des écoles','Native label was overwritten or copied-English description was not translated');$passed[]='valid native title wins while copied-English description falls back independently';
$coreClone=clone $entity;(new ReflectionProperty(CoreMenuPlugin::class,'entity'))->setValue($plugin,$coreClone->getTranslation('fr'));$manager->current='de';$assert($plugin->getTitle()==='Schulen'&&$plugin->getDescription()==='Schulen finden','Copied-English target blocked catalog');$passed[]='copied-English native fields use catalog even with a cached French entity clone';
$manager->current='it';$assert($plugin->getTitle()==='Schools'&&$plugin->getDescription()==='Find schools','Missing catalog changed source fallback');$passed[]='missing catalog entry keeps original text';
$manager->current='en';$assert($plugin->getTitle()==='Schools','English interface did not show actual base source');$passed[]='English interface uses actual entity source instead of stale plugin definition';
$manager->current='ar';$assert($plugin->getTitle()==='المدارس','RTL interface fallback failed');$manager->current='es';$assert($plugin->getTitle()==='Escuelas','Cached entity clone leaked a previous interface language');$passed[]='same plugin follows repeated interface-language changes without leaking labels';
$assert(in_array('languages:language_interface',$plugin->getCacheContexts(),TRUE),'Language cache context absent');$passed[]='render caching varies by interface language';
$assert($plugin->getPluginDefinition()===$definition&&$plugin->getRouteName()==='entity.node.canonical'&&$plugin->getRouteParameters()===['node'=>42]&&$plugin->getOptions()===['attributes'=>['data-example'=>'preserved']]&&$plugin->getMenuName()==='main'&&$plugin->getParent()==='menu_link_content:parent-portable'&&$plugin->getWeight()===7&&$plugin->isEnabled()&&$plugin->isExpanded(),'Menu structure/navigation properties changed');$passed[]='route, parameters, options, parent, weight, visibility and definition stay intact';
$assert($entity->getUntranslated()->toArray()===$before&&$entity->getTranslation('fr')->toArray()===$beforeFrench&&$entity->isNew()&&!$entity->id(),'Rendering mutated or saved a menu entity');$passed[]='source and native entity data remain unchanged with no IDs allocated';
$assert($log->get('portable_menu_lookup')===[],'Menu text rendering issued SQL');$passed[]='warm label lookup executes zero SQL';
[$other,$otherEntity]=$make('22222222-2222-4222-8222-222222222222','en');$manager->current='es';$assert($other->getTitle()===$plugin->getTitle()&&$otherEntity->uuid()!==$entity->uuid(),'Catalog keys depend on environment UUID');$passed[]='different environment UUID/base-language metadata uses the same portable source key';
foreach($catalog->calls as$call)$assert(count($call)===3&&$call[2]===''&&!str_contains($call[1],'11111111')&&!str_contains($call[1],'22222222'),'Catalog key contains environment identity');
$definitions=['content'=>$definition,'static'=>['class'=>'Drupal\\Core\\Menu\\MenuLinkDefault','title'=>'Schools'],'special'=>['class'=>'Drupal\\special\\DynamicMenuLink','title'=>'Schools']];$original=$definitions;labdoo_translations_menu_links_discovered_alter($definitions);$assert($definitions['content']['class']===CatalogMenuLinkContent::class&&$definitions['static']===$original['static']&&$definitions['special']===$original['special'],'Hook modified static/special plugins');unset($definitions['content']['class'],$original['content']['class']);$assert($definitions['content']===$original['content'],'Discovery hook changed menu text/structure');$passed[]='discovery changes only the exact core content-menu class';
$storage=new class extends \Drupal\Core\Menu\MenuTreeStorage {public $definitions=[];public function __construct(){}public function load($id){return $this->definitions[$id]??FALSE;}};
$external=$definition;$external['url']='https://example.org/donate';$external['route_name']=NULL;$external['discovered']=0;$storage->definitions=['external'=>$external,'special'=>$original['special']];$menuManager=new \Drupal\labdoo_translations\CatalogMenuLinkManager($storage,\Drupal::service('menu_link.static.overrides'),\Drupal::moduleHandler());$runtime=$menuManager->getDefinition('external');$expected=$external;$expected['class']=CatalogMenuLinkContent::class;$assert($runtime===$expected&&$storage->definitions['external']===$external&&$menuManager->getDefinition('special')===$original['special']&&$menuManager->getDefinition('missing',FALSE)===FALSE,'Runtime manager altered persisted definitions or skipped undiscovered content links');$passed[]='undiscovered external content links receive runtime fallback without storage writes';
$builder=new \Drupal\Core\DependencyInjection\ContainerBuilder;$builder->register('plugin.manager.menu.link',\Drupal\Core\Menu\MenuLinkManager::class)->setArguments(['preserved-argument']);$provider=new \Drupal\labdoo_translations\LabdooTranslationsServiceProvider;$provider->alter($builder);$assert($builder->getDefinition('plugin.manager.menu.link')->getClass()===\Drupal\labdoo_translations\CatalogMenuLinkManager::class&&$builder->getDefinition('plugin.manager.menu.link')->getArguments()===['preserved-argument'],'Core manager override changed constructor dependencies');$builder->getDefinition('plugin.manager.menu.link')->setClass('CustomSpecializedMenuManager');$provider->alter($builder);$assert($builder->getDefinition('plugin.manager.menu.link')->getClass()==='CustomSpecializedMenuManager','Specialized menu manager overwritten');$passed[]='container extends only the standard core manager while preserving dependencies';
$updated=$plugin->updateLink(['weight'=>9],FALSE);$assert($updated['class']===CoreMenuPlugin::class&&$updated['weight']===9&&$entity->isNew()&&!$entity->id(),'Runtime class would persist after a normal edit');$passed[]='normal definition updates preserve portable core class without saving an entity';
echo json_encode(['passed'=>count($passed),'cases'=>$passed],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}finally{$container->set('labdoo_translations.catalog',$previous);}
