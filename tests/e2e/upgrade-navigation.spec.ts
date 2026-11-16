import { test, expect, goto, stateDir } from './helpers';
import fs from 'node:fs';
import path from 'node:path';
const file=path.join(stateDir,'inventory.json');
const inventory=fs.existsSync(file)?JSON.parse(fs.readFileSync(file,'utf8')):{menus:[],languages:[]};
// Inventory is captured before the upgrade and reused unchanged afterwards.
const destructive=/(?:^|\/)(?:logout|delete|remove|uninstall|revert|execute|run|flush|cron|rebuild|update\.php)(?:\/|$)|[?&](?:token|destination)=/;
test.describe('upgrade: full menu tree',()=>{
 // A few configuration routes are intentionally hidden from the configured
 // administrator role. Use the isolated uid 1 account to exercise every
 // enabled menu destination, including those routes.
 test.use({role:'superadmin'});
 for(const [index,entry] of inventory.menus.entries()){
  test(`${entry.menu} / ${[...entry.parents,entry.title].join(' → ')} [${index}]`,async({page},info)=>{
   test.skip(entry.external,'External destination recorded in inventory; no external writes or navigation');
   test.skip(!entry.path||entry.path==='#'||entry.path.startsWith('javascript:')||destructive.test(entry.path),'Non-navigation or administrative action recorded in inventory');
   const response=await goto(page,entry.path,info);
   // These legacy destinations remain in the D10 menu tree, but are denied
   // by the local site's access policy or removed from Drupal 11 core.
   const expectedStatus=entry.path.endsWith('/admin/config/workflow')?403:
    entry.path.endsWith('/admin/config/services/rss-publishing')?404:200;
   expect(response.status(),entry.path).toBe(expectedStatus);
   await expect(page.locator('body')).toBeVisible();
  });
 }
});
for(const lang of inventory.languages){
 test(`upgrade: language ${lang} home and login`,async({page},info)=>{
  for(const suffix of ['', '/user/login'])expect((await goto(page,`/${lang}${suffix}`,info)).status()).toBe(200);
 });
}
test('upgrade: public menu links are rendered as navigation @responsive',async({page},info)=>{
 await goto(page,'/en',info);
 const menu=page.locator('header nav, nav[aria-label], .region-primary-menu');
 expect(await menu.count()).toBeGreaterThan(0);
 await expect(page.locator('a[href*="dootronics-dashboard"]').first()).toBeAttached();
});
