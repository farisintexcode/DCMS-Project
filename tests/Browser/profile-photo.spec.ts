import { test, expect } from './fixtures';
import path from 'node:path';
for (const portal of ['Admin', 'Rider']) {
    test(`${portal} photo picker preview crop and cancel preserve profile layout`, async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(portal === 'Admin' ? '/login' : '/rider/login');
        await page.getByLabel('Email').fill(portal === 'Admin' ? 'admin@example.test' : 'rider@example.test');
        await page.getByLabel('Password', { exact: true }).fill(process.env.DCMS_E2E_PASSWORD!);
        await page.getByRole('button', { name: 'Sign in', exact: true }).click();
        await page.waitForURL(portal === 'Admin' ? '**/admin' : '**/rider');
        await page.goto('/account');
        await expect(page.getByRole('heading', { name: 'Change password' })).toBeVisible();
        const picker = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: 'Edit profile photo' }).click();
        await (await picker).setFiles(path.resolve('public/images/brand/nexora-app-180.png'));
        const dialog = page.getByRole('dialog', { name: 'Update profile photo' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Save Photo' })).toBeEnabled();
        await dialog.getByLabel('Zoom', { exact: true }).press('End');
        await expect(dialog.getByLabel('Zoom', { exact: true })).toHaveValue('3');
        await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeInViewport();
        await page.screenshot({ path: `.local-tools/profile-photo-${portal.toLowerCase()}-${test.info().project.name}.png` });
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(page.getByRole('button', { name: 'Edit profile photo' })).toBeFocused();
        await page.locator('input[type=file]').setInputFiles(path.resolve('public/images/brand/nexora-app-180.png'));
        await expect(dialog).toBeVisible();
        await expect(dialog.getByLabel('Zoom', { exact: true })).toHaveValue('1');
        await expect(dialog.getByRole('button', { name: 'Save Photo' })).toBeEnabled();
        await page.evaluate(() => document.documentElement.classList.add('dark'));
        await page.screenshot({ path: `.local-tools/profile-photo-dark-${portal.toLowerCase()}-${test.info().project.name}.png` });
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        expect(errors).toEqual([]);
    });
}
