import { expect, test } from './fixtures';

test('Nexora branding is consistent on public entry and authentication screens', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    for (const route of ['/', '/login', '/rider/login', '/forgot-password']) {
        const response = await page.goto(route);
        expect(response?.status()).toBe(200);
        await expect(page).toHaveTitle(/Nexora/);
        await expect(page.locator('body')).not.toContainText(/DebtOps|InteXcode|DCMS/);
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', /nexora-browser-32\.png(?:\?v=navy3)?$/);
        const logo = page.locator('.nexora-logo-full img').filter({ visible: true }).first();
        await expect(logo).toHaveAttribute('src', /nexora-logo-(light|dark)\.svg$/);
        expect(await logo.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    }
    await page.goto('/login');
    await page.screenshot({ path: '.local-tools/nexora-login-' + test.info().project.name + '.png', fullPage: true });
});
