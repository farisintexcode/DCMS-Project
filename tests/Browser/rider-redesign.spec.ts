import { expect, test } from './fixtures';

test('Rider moves between assignments tasks attendance profile and updates using the redesigned shell', async ({ page }) => {
    test.setTimeout(240000);
    const password=process.env.DCMS_E2E_PASSWORD;
    test.skip(!password,'Requires isolated synthetic Rider UI fixtures.');
    const surface=test.info().project.name;
    const errors:string[]=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.emulateMedia({colorScheme:'light',reducedMotion:'reduce'});
    await page.goto('/rider/login');
    await page.getByLabel('Email',{exact:true}).fill('rider@example.test');
    await page.getByLabel('Password',{exact:true}).fill(password!);
    await page.getByRole('button',{name:'Sign in',exact:true}).click();
    await expect(page).toHaveURL(/\/rider$/);
    const nav=page.getByRole('navigation',{name:'Rider navigation',exact:true});
    await expect(nav.getByRole('link')).toHaveText(['Home','Assignment','My Task','Attendance']);
    await expect(page.locator('header').getByRole('link',{name:'Profile',exact:true})).toBeVisible();
    await expect(page.locator('header').getByRole('button',{name:'Sign out',exact:true})).toHaveCount(0);
    await page.screenshot({path:'.local-tools/rider-redesign-'+surface+'-home.png'});
    await nav.getByRole('link',{name:'Assignment',exact:true}).click();
    const batch=page.locator('article').filter({has:page.getByRole('heading',{name:'Synthetic Rider Batch '+surface,exact:true})});
    await batch.getByRole('link',{name:'View Assignment',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Your allocated tasks',exact:true})).toBeVisible();
    await expect(nav.getByRole('link',{name:'Assignment',exact:true})).toHaveAttribute('aria-current','page');
    const task=page.locator('article').filter({has:page.getByRole('heading',{name:'Synthetic Field Customer '+surface,exact:true})});
    await task.getByRole('link',{name:'View Task',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Task details',exact:true})).toBeVisible();
    await expect(nav.getByRole('link',{name:'My Task',exact:true})).toHaveAttribute('aria-current','page');
    await page.getByRole('button',{name:'Customer',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Customer context',exact:true})).toBeVisible();
    await page.screenshot({path:'.local-tools/rider-redesign-'+surface+'-task.png'});
    await nav.getByRole('link',{name:'Attendance',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Your attendance',exact:true}).first()).toBeVisible();
    await page.locator('header').getByRole('link',{name:'Profile',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Account settings',exact:true})).toBeVisible();
    await page.locator('header').getByRole('link',{name:'Notifications',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Notifications',exact:true})).toBeVisible();
    for(const route of ['/rider','/rider/assignments','/rider/tasks','/rider/attendance','/account','/rider/notifications']) {
        await page.goto(route);
        for(const width of [320,430,768]) {
            await page.setViewportSize({width,height:900});
            await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
            const footer=await nav.boundingBox();
            expect(footer!.y+footer!.height).toBeCloseTo(900,0);
        }
    }
    await page.getByRole('button',{name:'Menu',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Rider sidebar',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Close menu',exact:true})).toBeFocused();
    await page.getByRole('button',{name:'Switch to dark mode',exact:true}).click();
    await page.screenshot({path:'.local-tools/rider-redesign-'+surface+'-menu.png'});
    await page.getByRole('button',{name:'Sign out',exact:true}).click();
    await expect(page).toHaveURL(/\/rider\/login$/);
    expect(errors).toEqual([]);
});
