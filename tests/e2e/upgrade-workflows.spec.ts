import { test,expect,goto,fixtures } from './helpers';

test('upgrade: global search finds seeded content and an unmatched query is empty',async({page},info)=>{
 await goto(page,'/en/search-results',info);
 await page.getByRole('link',{name:'Open search',exact:true}).first().click();
 const search=page.locator('[name="search_api_fulltext"]:visible').first();
 await search.fill('E2E searchable post text');
 await search.locator('xpath=ancestor::form').locator('[type=submit]').first().press('Enter');
 await expect(page.locator('main')).toContainText(fixtures(info).nodes['Team Post'].title);
 await goto(page,'/en',info);
 await page.getByRole('link',{name:'Open search',exact:true}).first().click();
 await search.fill('E2Eunmatched928374928374');await search.locator('xpath=ancestor::form').locator('[type=submit]').first().press('Enter');
 await expect(page.locator('main .views-row')).toHaveCount(0);
});
test('upgrade: contact webform validates and stores a submission with local mail @responsive',async({page},info)=>{
 await goto(page,'/en/content/contact-labdoo',info);
 const form=page.locator('form.webform-submission-contact-labdoo-add-form');
 await expect(form).toBeVisible();
 await expect(form).toHaveAttribute('novalidate','');
 await form.locator('[type=submit]').first().click();
 await expect(page.locator('.messages--error')).toBeVisible();
 await expect(page.locator('.webform-confirmation')).toHaveCount(0);
 await form.locator('[name=name]').fill('E2E contact');
 for(const input of await form.locator('input[type=email]').all())await input.fill('e2e-contact@example.invalid');
 await form.locator('select[name="country[country]"]').selectOption('Spain',{force:true});
 await form.locator('[name="the_reason_you_are_contacting_labdoo_org"]').selectOption('5',{force:true});
 await form.locator('[name=message]').fill('E2E migration contact submission; no external mail.');
 await form.locator('[type=submit]').first().press('Enter');
 await expect(page.locator('.webform-confirmation').first()).toBeVisible();
});
test.describe('upgrade: team collaboration and account navigation',()=>{
 test.use({role:'basic'});
 test('join and leave a team',async({page},info)=>{
  const id=fixtures(info).nodes.Team.id;
  await goto(page,`/en/team/join/${id}`,info);
  await expect(page.locator('.messages--status')).toContainText('joined');
  await goto(page,`/en/team/leave/${id}`,info);
  await expect(page.locator('.messages--status')).toContainText('left');
 });
 test('personal dashboard, contributions and roles',async({page},info)=>{
  for(const suffix of ['dashboard','metrics','roles'])expect((await goto(page,`/en/user/${fixtures(info).accounts.basic.id}/${suffix}`,info)).status()).toBe(200);
 });
});
test.describe('upgrade: comments',()=>{
 test.use({role:'admin'});
 test('post, edit and delete a team comment',async({page},info)=>{
  const title=`E2E comment ${Date.now()}`;
  await goto(page,`/en/team/leave/${fixtures(info).nodes.Team.id}`,info);
  await goto(page,fixtures(info).nodes['Team Post'].path,info);
  await expect(page.locator('form.comment-form')).toHaveCount(0);
  await goto(page,`/en/team/join/${fixtures(info).nodes.Team.id}`,info);
  await goto(page,fixtures(info).nodes['Team Post'].path,info);
  const form=page.locator('form.comment-form');await expect(form).toBeVisible();
  const subject=form.locator('[name="subject[0][value]"]');if(await subject.isVisible())await subject.fill(title);
  const editor=form.locator('.ck-editor__editable');
  await expect(editor).toBeVisible();await editor.press('ControlOrMeta+A');await editor.pressSequentially(title);
  await form.locator('[type=submit]').first().press('Enter');
  const comment=page.locator('article.comment').filter({hasText:title});await expect(comment).toBeVisible();
  await comment.getByRole('link',{name:'Edit',exact:true}).click();
  const edit=page.url();const editEditor=page.locator('main .ck-editor__editable');
  await expect(editEditor).toBeVisible();await editEditor.press('ControlOrMeta+A');await editEditor.pressSequentially(title+' updated');
  await page.locator('main [data-drupal-selector="edit-submit"]').press('Enter');
  await expect(page.locator('article.comment').filter({hasText:title+' updated'})).toBeVisible();
  await goto(page,edit.replace(/\/edit(?:\?.*)?$/,'/delete'),info);await page.locator('main [data-drupal-selector="edit-submit"]').press('Enter');
  await expect(page.locator('article.comment').filter({hasText:title})).toHaveCount(0);
  await goto(page,`/en/team/leave/${fixtures(info).nodes.Team.id}`,info);
 });
});
