from pathlib import Path
import json,hashlib,tempfile,subprocess,copy,os,argparse
root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser(description='Offline roundtrip and adversarial catalog CLI regression; never bootstraps Drupal.')
parser.add_argument('--autoload',type=Path,default=root/'vendor/autoload.php')
arguments=parser.parse_args()
script=root/'scripts/labdoo-translations-catalog.php';catalog=root/'web/modules/custom/labdoo_translations/catalog';autoload=arguments.autoload;passed=[]
source_count=len(json.loads((catalog/'source.json').read_text())['strings'])
def execute(action,*args,ok=True):
 r=subprocess.run(['php','-d','xdebug.mode=off',str(script),action,'--autoload='+str(autoload),*args],capture_output=True,text=True,cwd=root)
 assert (r.returncode==0)==ok,{'action':action,'exit':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
 return r

def tree(path):return {p.name:p.read_bytes()for p in Path(path).iterdir()if p.is_file()}
def checksum(entry):entry['sha256']=hashlib.sha256(entry['contents'].encode()).hexdigest()
with tempfile.TemporaryDirectory(prefix='labdoo-catalog-transfer-')as temporary:
 temporary=Path(temporary);os.chmod(temporary,0o700);bundle=temporary/'bundle.json';dest=temporary/'catalog';
 report=json.loads(execute('check').stdout);assert len(report['languages'])==20 and report['source_entries']==source_count;passed.append('check validates all twenty real catalogs and all source entries offline')
 execute('export','--file='+str(bundle));original=json.loads(bundle.read_text());assert len(original['files'])==21;passed.append('export contains source plus twenty catalogs with exact SHA256 checksums')
 expected={k:v['contents'].encode()for k,v in original['files'].items()};assert tree(catalog)==expected
 imported=json.loads(execute('import','--file='+str(bundle),'--catalog='+str(dest)).stdout);assert tree(dest)==expected;passed.append('import roundtrip preserves all twenty-one files byte for byte')
 imported2=json.loads(execute('import','--file='+str(bundle),'--catalog='+str(dest)).stdout);backup=Path(imported2['backup_directory']);assert tree(dest)==expected and tree(backup)==expected;passed.append('replacement retains exact previous directory as backup')
 execute('export','--file='+str(bundle),ok=False);assert json.loads(bundle.read_text())==original;passed.append('export refuses overwrite and preserves existing bundle')
 def rejected(name,mutation):
  value=copy.deepcopy(original);mutation(value);file=temporary/(name+'.json');file.write_text(json.dumps(value,ensure_ascii=False));before=tree(dest);siblings={p.name for p in temporary.iterdir()};r=execute('import','--file='+str(file),'--catalog='+str(dest),ok=False);assert tree(dest)==before and {p.name for p in temporary.iterdir()}==siblings,{'case':name,'stderr':r.stderr,'staging_residue':sorted({p.name for p in temporary.iterdir()}-siblings)};passed.append(name+' rejected before destination changes; staging cleaned')
 rejected('invalid_checksum',lambda value:value['files']['fr.json'].__setitem__('contents',value['files']['fr.json']['contents']+' '))
 rejected('path_traversal',lambda value:value['files'].__setitem__('../outside.json',{'contents':'outside','sha256':hashlib.sha256(b'outside').hexdigest()}))
 assert not (temporary.parent/'outside.json').exists()
 def bad_placeholder(value):
  d=json.loads(value['files']['sw.json']['contents']);s=json.loads(value['files']['source.json']['contents'])['strings'];key=next(k for k,v in s.items()if v['source']=='@interval ago');d['translations'][key]=d['translations'][key].replace('@interval','@changed');entry=value['files']['sw.json'];entry['contents']=json.dumps(d,ensure_ascii=False);checksum(entry)
 rejected('modified_placeholder_with_valid_checksum',bad_placeholder)
 def bad_plurals(value):
  entry=value['files']['source.json'];d=json.loads(entry['contents']);d['plurals']['ar']['nplurals']=2;entry['contents']=json.dumps(d,ensure_ascii=False);checksum(entry)
 rejected('modified_plural_metadata_with_valid_checksum',bad_plurals)
 def bad_yaml(value):
  s=json.loads(value['files']['source.json']['contents'])['strings'];key=next(k for k,v in s.items()if v['source']=='Yes: Yes\nNo: No\n');entry=value['files']['fr.json'];d=json.loads(entry['contents']);d['translations'][key]='Oui: Oui\nNon: Non\n';entry['contents']=json.dumps(d,ensure_ascii=False);checksum(entry)
 rejected('changed_yaml_option_keys_with_valid_checksum',bad_yaml)
 symlink=temporary/'symlink';symlink.symlink_to(dest,target_is_directory=True);before=tree(dest);execute('import','--file='+str(bundle),'--catalog='+str(symlink),ok=False);assert tree(dest)==before;passed.append('symlink destination refused without changing target')
 result={'passed':len(passed),'cases':passed,'files_compared_byte_exact':21,'languages':20,'source_entries':source_count,'drupal_bootstrapped':False,'database_access':False,'production_changes':False,'script_sha256':hashlib.sha256(script.read_bytes()).hexdigest()};print(json.dumps(result,indent=2))
