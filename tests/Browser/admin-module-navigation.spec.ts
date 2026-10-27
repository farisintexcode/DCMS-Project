import { expect, test } from './fixtures';

test('Admin module groups use unique existing destinations and track their active pages',async({page})=>{
 const password=process.env.DCMS_E2E_PASSWORD;test.skip(!password,'Synthetic preview account required');
 const errors:string[]=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill('admin@example.test');await page.getByLabel('Password',{exact:true}).fill(password!);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL('**/admin');
 const sidebar=page.locator('#admin-navigation');
 const desktop=test.info().project.name==='desktop';
 if(!desktop)await page.getByRole('button',{name:'Menu',exact:true}).click();
 await expect(sidebar.getByRole('link',{name:'Dashboard',exact:true})).toHaveAttribute('aria-current','page');
 const mappings=[['Assignment','Add Assignment','/admin/assignments/create'],['Task Management','Assign Task','/admin/tasks/allocate'],['Human Resources','Add New Staff','/admin/staff/create']];
 for(const [group,label,url] of mappings){
  const button=sidebar.getByRole('button',{name:group,exact:true});await button.click();await expect(button).toHaveAttribute('aria-expanded','true');
  await sidebar.getByRole('link',{name:label,exact:true}).click();await expect(page).toHaveURL(new RegExp(url+'$'));
  if(!desktop)await page.getByRole('button',{name:'Menu',exact:true}).click();
  await expect(sidebar.getByRole('button',{name:group,exact:true})).toHaveAttribute('data-active-parent','true');
  await expect(sidebar.getByRole('link',{name:label,exact:true})).toHaveAttribute('aria-current','page');
  await sidebar.getByRole('button',{name:group,exact:true}).click();await expect(sidebar.getByRole('link',{name:label,exact:true})).toBeHidden();
 }
 const hrefs=await sidebar.locator('nav a[href]').evaluateAll(els=>els.map(el=>el.getAttribute('href')));expect(new Set(hrefs).size).toBe(hrefs.length);
 await sidebar.getByRole('button',{name:'Task Management',exact:true}).click();await expect(sidebar.getByRole('link',{name:'Upload Task',exact:true})).toHaveAttribute('href',/\/admin\/imports$/);await expect(sidebar.getByRole('link',{name:'Task Verification',exact:true})).toHaveAttribute('href',/\/admin\/verification$/);
 await expect(sidebar.getByRole('link',{name:'Report',exact:true})).toHaveAttribute('href',/\/admin\/reports$/);
 expect(errors).toEqual([]);await page.screenshot({path:'.local-tools/admin-module-navigation-'+test.info().project.name+'.png'});
});
