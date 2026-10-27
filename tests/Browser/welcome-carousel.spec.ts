import { expect, test } from './fixtures';

test('welcome carousel rotates loops and pauses without changing the login form', async ({ page }) => {
 const errors:string[]=[]; page.on('pageerror',e=>errors.push(e.message));
 await page.emulateMedia({reducedMotion:'no-preference'});
 await page.clock.install(); await page.goto('/login');
 const carousel=page.getByRole('region',{name:'Explore Nexora'});
 await expect(carousel).toBeVisible();
 await page.mouse.move(0,0);
 await expect(carousel.getByRole('heading',{name:'Clarity for every collection.'})).toBeVisible();
 await page.clock.fastForward(5100);
 await expect(carousel.getByRole('heading',{name:'Built for field teams.'})).toBeVisible();
 await carousel.getByRole('button',{name:'Show slide 4: Secure and controlled access.',exact:true}).click();
 await page.clock.fastForward(20000);
 await expect(carousel.getByRole('heading',{name:'Secure and controlled access.'})).toBeVisible();
 await page.getByLabel('Email',{exact:true}).focus(); await page.mouse.move(0,0);
 await page.clock.fastForward(11000);
 await expect(carousel.getByRole('heading',{name:'Clarity for every collection.'})).toBeVisible();
 await carousel.getByRole('button',{name:'Pause slideshow',exact:true}).click();
 await page.getByLabel('Email',{exact:true}).focus(); await page.mouse.move(0,0);
 await page.clock.fastForward(20000);
 await expect(carousel.getByRole('heading',{name:'Clarity for every collection.'})).toBeVisible();
 await carousel.evaluate(el => { const start=new Touch({identifier:1,target:el,clientX:250,clientY:100}); const end=new Touch({identifier:1,target:el,clientX:100,clientY:105}); el.dispatchEvent(new TouchEvent('touchstart',{touches:[start],bubbles:true})); el.dispatchEvent(new TouchEvent('touchend',{changedTouches:[end],bubbles:true})); });
 await expect(carousel.getByRole('heading',{name:'Built for field teams.'})).toBeVisible();
 await expect(page.getByRole('button',{name:'Sign in',exact:true})).toBeVisible();
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
 expect(errors).toEqual([]);
 await page.clock.runFor(500);
 await page.screenshot({path:'.local-tools/welcome-carousel-'+test.info().project.name+'.png',fullPage:true});
});

test('reduced motion keeps welcome content stable with manual slide selection',async({page})=>{
 await page.emulateMedia({reducedMotion:'reduce'}); await page.clock.install();await page.goto('/rider/login');
 const carousel=page.getByRole('region',{name:'Explore Nexora'});
 await page.clock.fastForward(20000);
 await expect(carousel.getByRole('heading',{name:'On the road. Always in control.'})).toBeVisible();
 await carousel.getByRole('button',{name:'Show slide 3: Stay on top of every task.',exact:true}).click();
 await expect(carousel.getByRole('heading',{name:'Stay on top of every task.'})).toBeVisible();
 await expect(page.getByRole('heading',{name:'Rider sign in',exact:true})).toBeVisible();
});
