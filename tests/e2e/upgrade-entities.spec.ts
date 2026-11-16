import { test, expect, fixtures, goto, stateDir } from './helpers';
import fs from 'node:fs';
import path from 'node:path';
import type { Page } from '@playwright/test';
const file=path.join(stateDir,'inventory.json');
const inventory=fs.existsSync(file)?JSON.parse(fs.readFileSync(file,'utf8')):{bundles:{},entityTypes:{}};
async function fillFields(page:Page,title:string,info:any){
 const data=fixtures(info);
 // Populate the actual widgets, including hidden Chosen selects and CKEditor.
 await page.locator('main details').evaluateAll(els=>els.forEach(e=>e.setAttribute('open','')));
 for(const select of await page.locator('main form select[name*=country]').all()){if(await select.locator('option[value=ES]').count()&&await select.inputValue()!=='ES'){await select.selectOption('ES',{force:true});await page.waitForFunction(()=>!(window as any).jQuery?.active&&!document.querySelector('.ajax-progress'));}}
 const values:Record<string,string>={field_model:title,field_serial_number:title,field_city:'Madrid',field_dootrip_capacity:'3',field_number_of_laptops_needed:'5',field_number_of_days_before:'1',field_number_of_days_after:'1',field_edoovillage_name:title,field_description:'E2E lifecycle description',field_hub_name:title};
 const references:Record<string,string>={field_parent:`${data.nodes.School.title} (${data.nodes.School.id})`,field_hub:`${data.nodes['Hub Alpha'].title} (${data.nodes['Hub Alpha'].id})`,field_team:`${data.nodes.Team.title} (${data.nodes.Team.id})`,field_manager:`${data.accounts.admin.name} (${data.accounts.admin.id})`,field_project_manager_s_:`${data.accounts.admin.name} (${data.accounts.admin.id})`,field_hub_manager_s_:`${data.accounts.admin.name} (${data.accounts.admin.id})`,field_dootripper_s:`${data.accounts.admin.name} (${data.accounts.admin.id})`};
 for(const input of await page.locator('main form input:not([type=hidden]):not([type=submit]):not([type=button]), main form textarea').all()){
  const name=await input.getAttribute('name')||'',type=await input.getAttribute('type')||'',required=await input.getAttribute('required')!==null;
  if(type==='checkbox'&&(name.startsWith('field_types_of_mini_missions[')||name.startsWith('field_hub_manager_notifications['))&&await input.isVisible()){await input.check();continue;}
  if(['checkbox','radio','file','password'].includes(type)||await input.getAttribute('readonly')!==null)continue;
  if(!await input.isVisible()&&await input.evaluate(e=>e.tagName!=='TEXTAREA'))continue;
  if(await input.evaluate(e=>e.tagName==='TEXTAREA')){await input.evaluate((e,v)=>{(e as HTMLTextAreaElement).value=v;},`${title} body content`);continue;}
  if(name.includes('[target_id]')&&required&&!(await input.inputValue())){const field=name.split('[')[0],bundle=title.match(/^E2E lifecycle ([\w_]+)/)?.[1]||'',definition=inventory.bundles[bundle]?.fields[field];if(definition?.settings.target_type==='user'){await input.fill(`${data.accounts.admin.name} (${data.accounts.admin.id})`);continue;}}
  if(name.startsWith('title[')||name.startsWith('label[')||name==='name[0][value]')await input.fill(title);
  else if(name.includes('[target_id]')&&references[name.split('[')[0]])await input.fill(references[name.split('[')[0]]);
  else if(values[name.split('[')[0]])await input.fill(values[name.split('[')[0]]);
  else if(name.includes('geocode'))await input.fill('Madrid, Spain');
  else if(name.includes('[lat]'))await input.fill('40.4168');
  else if(name.includes('[lon]')||name.includes('[lng]'))await input.fill('-3.7038');
  else if(type==='date')await input.fill(name.includes('arrival')?'2027-06-02':'2027-06-01');
  else if(type==='time')await input.fill('12:00:00');
  else if(name.endsWith('[value]')&&await input.evaluate(e=>e.tagName==='TEXTAREA')){
   await input.evaluate((e,v)=>{(e as HTMLTextAreaElement).value=v;e.dispatchEvent(new Event('input',{bubbles:true}));},`<p>${title} body content</p>`);
  }else if(required&&!(await input.inputValue()))await input.fill(type==='number'?'1':type==='email'?'e2e@example.invalid':title);
 }
 for(const select of await page.locator('main form select').all()){
  const name=await select.getAttribute('name')||'';
  if((await select.getAttribute('required'))!==null&&['','_none'].includes(await select.inputValue())){
   const option=await select.locator('option').evaluateAll(els=>els.map(e=>({v:(e as HTMLOptionElement).value,disabled:(e as HTMLOptionElement).disabled})).find(e=>!e.disabled&&!['','_none'].includes(e.v))?.v);
   if(option)await select.selectOption(option,{force:true});
  }
 }
 for(const editor of await page.locator('main .ck-editor__editable[contenteditable=true]').all())if(await editor.isVisible()){await editor.press('ControlOrMeta+A');await editor.pressSequentially(`${title} body content`);}
}
async function fillForm(page:Page,title:string,info:any){
 const tabs=page.locator('main .horizontal-tabs-list a, main .vertical-tabs__menu a');
 const hrefs=await tabs.evaluateAll(es=>es.map(e=>e.getAttribute('href')));
 if(hrefs.length){for(const href of hrefs){await page.locator(`main a[href="${href}"]`).first().click();await fillFields(page,title,info);}}
 else await fillFields(page,title,info);
}
async function submit(page:Page){await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('main [data-drupal-selector="edit-submit"]').press('Enter')]);}
async function save(page:Page){
 await submit(page);
 await expect(page.locator('.messages--error, [role=alert].messages--error')).toHaveCount(0);
}
test.describe('upgrade: node lifecycle',()=>{
 test.use({role:'admin'});
 for(const bundle of Object.keys(inventory.bundles)){
  test.describe(bundle,()=>{
   test.use({role:bundle==='gallery'?'superadmin':'admin'});
  test(`create, read, update and delete ${bundle}`,async({page},info)=>{
   test.setTimeout(120000);
   const title=`E2E lifecycle ${bundle} ${Date.now()}`;
   expect((await goto(page,`/en/node/add/${bundle}${bundle==='labdoo_story'?'?parent_id='+fixtures(info).nodes.School.id:''}`,info)).status()).toBe(200);
   await fillForm(page,title,info);
   if(bundle==='gallery'){
    await page.locator('main .js-media-library-open-button').press('Enter');
    const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible();
    await dialog.locator('input[type=checkbox]').first().check();
    await dialog.getByRole('button',{name:/Insert selected/}).press('Enter');
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('main [name="field_images[selection][0][target_id]"]')).toHaveValue(/^\d+$/);
   }
   await save(page);
   await expect(page).not.toHaveURL(new RegExp(`/node/add/${bundle}`));
   // Canonical link also handles automatically generated aliases and titles.
   const edit=page.locator('a[href$="/edit"][href*="/node/"]').first();
   const editPath=await edit.getAttribute('href');expect(editPath).toMatch(/\/node\/\d+\/edit$/);
   const id=editPath!.match(/\/node\/(\d+)\/edit$/)![1];
   await goto(page,`/en/node/${id}/edit`,info);
   await fillForm(page,title+' updated',info);
   await save(page);
   await goto(page,`/en/node/${id}/edit`,info);
   const titleInput=page.locator('[name="title[0][value]"]');
   if(await titleInput.isVisible()&&await titleInput.isEnabled())await expect(titleInput).toHaveValue(title+' updated');
   await goto(page,`/en/node/${id}/delete`,info);
   await submit(page);
   expect((await goto(page,`/en/node/${id}`,info)).status()).toBe(404);
  });
  });
 }
});
test.describe('upgrade: supplementary entities',()=>{
 test.use({role:'admin'});
 test('taxonomy term create, edit and delete',async({page},info)=>{
  const title=`E2E term ${Date.now()}`;
  await goto(page,'/en/admin/structure/taxonomy/manage/tags/add',info);
  await page.locator('[name="name[0][value]"]').fill(title);await save(page);
  const link=page.locator('.messages--status a[href*="/taxonomy/term/"]').first();
  const href=await link.getAttribute('href');expect(href).toMatch(/\/taxonomy\/term\/\d+/);
  await goto(page,href!+'/edit',info);
  await page.locator('[name="name[0][value]"]').fill(title+' updated');await save(page);
  await goto(page,href!+'/edit',info);await expect(page.locator('[name="name[0][value]"]')).toHaveValue(title+' updated');
  await goto(page,href!+'/delete',info);await submit(page);
  expect((await goto(page,href!,info)).status()).toBe(404);
 });
 test('wiki page create, update and delete',async({page},info)=>{
  const title=`E2E wiki lifecycle ${Date.now()}`;
  await goto(page,'/en/wiki-page/add',info);await fillForm(page,title,info);await save(page);
  await expect(page.locator('h1').first()).toContainText(title);
  const href=await page.locator('a[href*="wiki-page/"][href$="/edit"]').first().getAttribute('href');
  await goto(page,href!,info);await fillForm(page,title+' updated',info);await save(page);
  await expect(page.locator('h1').first()).toContainText(title+' updated');
  await goto(page,href!.replace(/\/edit$/,'/delete'),info);await submit(page);
 });
 test('user account create, edit, block and cancel',async({page},info)=>{
  const email=`e2e-lifecycle-${Date.now()}@example.invalid`;
  await goto(page,'/en/admin/people/create',info);
  await page.locator('[name=mail]').fill(email);
  const name=page.locator('[name=name]');if(await name.isVisible())await name.fill(email.split('@')[0]);
  await page.locator('[name="pass[pass1]"]').fill('E2e-private-password-7482');await page.locator('[name="pass[pass2]"]').fill('E2e-private-password-7482');
  await fillForm(page,email.split('@')[0],info);
  await submit(page);
  await expect(page.locator('.messages--error')).toHaveCount(0);
  await goto(page,'/en/admin/people?mail='+encodeURIComponent(email),info);
  const row=page.locator('tr').filter({hasText:email.split('@')[0]});await row.getByRole('link',{name:/^Edit(?: |$)/}).first().click();
  const edit=page.url();await page.locator('[name=status][value="0"]').check();await save(page);
  await goto(page,edit.replace(/\/edit(?:\?.*)?$/,'/cancel'),info);
  const cancel=page.locator('[name=user_cancel_method][value=user_cancel_delete]');if(await cancel.count())await cancel.check();
  await submit(page);
  await expect(page.locator('.messages--error')).toHaveCount(0);
 });
});

test.describe('upgrade: media and files',()=>{
 test.use({role:'admin'});
 for(const bundle of (inventory.entityTypes.media?.bundles||[])){
  test(`upload, read, edit and delete media ${bundle}`,async({page},info)=>{
   test.setTimeout(90000);
   const title=`E2E media ${bundle} ${Date.now()}`;
   await goto(page,`/en/media/add/${bundle}`,info);
   const name=page.locator('[name="name[0][value]"]');if(await name.isVisible())await name.fill(title);
   if(bundle==='remote_video'){await page.locator('[name="field_media_oembed_video[0][value]"]').fill('https://www.youtube.com/watch?v=E2Etest1234&e2e_title='+encodeURIComponent(title));}
   else {
   const file=page.locator('main input[type=file]').first();
   const extension:Record<string,string>={image:'png',audio:'wav',video:'mp4',document:'pdf'};
   await file.setInputFiles({name:title+'.'+extension[bundle],mimeType:({image:'image/png',audio:'audio/wav',video:'video/mp4',document:'application/pdf'} as Record<string,string>)[bundle],buffer:fs.readFileSync(path.join(stateDir,`upload.${extension[bundle]}`))});
   await expect(page.locator('main input[type=file]').first()).toHaveCount(0,{timeout:15000});
   const alt=page.locator('main input[name$="[alt]"]');if(await alt.count())await alt.fill(title);
   }
   await page.locator('main details').evaluateAll(es=>es.forEach(e=>e.setAttribute('open','')));
   if(await name.isVisible())await name.fill(title);
   await save(page);
   await goto(page,'/en/admin/content/media?name='+encodeURIComponent(title),info);
   const row=page.locator('tr').filter({hasText:title});await row.getByRole('link',{name:/^Edit(?: |$)/}).first().click();
   const href=page.url();await page.locator('[name="status[value]"]').uncheck();await save(page);
   await goto(page,href,info);await expect(page.locator('[name="status[value]"]')).not.toBeChecked();
   await goto(page,href.replace(/\/edit(?:\?.*)?$/,'/delete'),info);await submit(page);
   expect((await goto(page,href.replace(/\/edit(?:\?.*)?$/,''),info)).status()).toBe(404);
  });
 }
});
test.describe('upgrade: blocks and menu entries',()=>{
 test.use({role:'admin'});
 test('custom block create, edit and delete',async({page},info)=>{
  const title=`E2E block ${Date.now()}`;
  await goto(page,'/en/block/add/basic',info);
  await page.locator('[name="info[0][value]"]').fill(title);await fillForm(page,title,info);await save(page);
  await goto(page,'/en/admin/content/block',info);
  const row=page.locator('tr').filter({hasText:title});await row.getByRole('link',{name:/^Edit(?: |$)/}).first().click();
  const edit=page.url();await page.locator('[name="info[0][value]"]').fill(title+' updated');await save(page);
  await goto(page,edit.replace(/\/edit(?:\?.*)?$/,'/delete'),info);await submit(page);
  await expect(page.locator('.messages--error')).toHaveCount(0);
 });
 test('menu entry create, follow, edit and delete',async({page},info)=>{
  const title=`E2E menu ${Date.now()}`;
  await goto(page,'/en/admin/structure/menu/manage/main/add',info);
  await page.locator('[name="title[0][value]"]').fill(title);
  await page.locator('[name="link[0][uri]"]').fill('/node/'+fixtures(info).nodes.School.id);await save(page);
  const edit=page.url();await expect(page.locator('[name="title[0][value]"]')).toHaveValue(title);await page.locator('[name="title[0][value]"]').fill(title+' updated');await save(page);
  await goto(page,'/en',info);await page.getByRole('link',{name:title+' updated',exact:true}).first().press('Enter');
  await expect(page.locator('h1').first()).toContainText(fixtures(info).nodes.School.title);
  await goto(page,edit.replace(/\/edit(?:\?.*)?$/,'/delete'),info);await submit(page);
 });
});
