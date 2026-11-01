import { expect, test } from './fixtures';
import path from 'node:path';

// M01 / UI05: real picker, local preview, crop controls, focus and safe failure.
for (const portal of ['admin', 'rider'] as const) {
    test(`${portal} profile photo editor preserves the account layout and supports preview and cancel`, async ({ page }) => {
        const email = portal === 'admin' ? process.env.DCMS_E2E_ADMIN_EMAIL : process.env.DCMS_E2E_RIDER_EMAIL;
        const password = process.env.DCMS_E2E_PASSWORD;
        test.skip(!email || !password, 'Requires isolated synthetic account fixtures.');
        const errors: string[] = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(portal === 'admin' ? '/login' : '/rider/login');
        await page.getByLabel('Email', { exact: true }).fill(email!);
        await page.getByLabel('Password', { exact: true }).fill(password!);
        await page.getByRole('button', { name: 'Sign in', exact: true }).click();
        await expect(page).toHaveURL(portal === 'admin' ? /\/admin$/ : /\/rider$/);
        await page.goto(portal === 'admin' ? '/account' : '/rider/profile');
        await expect(page.getByRole('heading', { name: portal === 'admin' ? 'Change password' : 'My Profile', exact: true })).toBeVisible();
        const edit = page.getByRole('button', { name: 'Edit profile photo', exact: true });
        await expect(edit).toBeVisible();
        await expect(edit).toHaveCSS('background-color', 'rgb(251, 133, 0)');
        const avatarShape = await page.locator('.profile-avatar').evaluate(element => ({ width: element.clientWidth, height: element.clientHeight, radius: parseFloat(getComputedStyle(element).borderRadius) }));
        expect(avatarShape.width).toBe(avatarShape.height);
        expect(avatarShape.radius).toBeGreaterThanOrEqual(avatarShape.width / 2);
        await page.screenshot({ path: test.info().outputPath(`${portal}-account-light.png`), fullPage: true });

        const picker = page.waitForEvent('filechooser');
        await edit.click();
        await (await picker).setFiles(path.resolve('public/images/brand/nexora-app-180.png'));
        const dialog = page.getByRole('dialog', { name: 'Update profile photo', exact: true });
        await expect(dialog).toBeVisible();
        const preview = dialog.getByAltText('Selected profile picture preview');
        await expect(preview).toHaveAttribute('src', /^blob:/);
        await expect(dialog.getByRole('button', { name: 'Save Photo', exact: true })).toBeEnabled();
        const saveButton = await dialog.getByRole('button', { name: 'Save Photo', exact: true }).boundingBox();
        expect(saveButton!.y + saveButton!.height).toBeLessThanOrEqual(page.viewportSize()!.height);
        await dialog.getByLabel('Zoom', { exact: true }).press('End');
        await expect(dialog.getByLabel('Zoom', { exact: true })).toHaveValue('3');
        await dialog.getByLabel('Horizontal position', { exact: true }).press('End');
        await expect(dialog.getByLabel('Horizontal position', { exact: true })).toHaveValue('100');
        await expect(preview).toHaveAttribute('style', /width:300%/);
        await page.screenshot({ path: test.info().outputPath(`${portal}-photo-preview-light.png`), fullPage: true });
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(edit).toBeFocused();
        await expect(page.getByAltText('Your profile picture', { exact: true })).toHaveCount(0);

        // Re-selecting the same file must open a clean editor; Escape also cancels.
        await page.getByLabel('Choose profile picture', { exact: true }).setInputFiles(path.resolve('public/images/brand/nexora-app-180.png'));
        await expect(dialog).toBeVisible();
        await expect(dialog.getByLabel('Zoom', { exact: true })).toHaveValue('1');
        await page.evaluate(() => document.documentElement.classList.add('dark'));
        await expect(dialog).toHaveCSS('color', 'rgb(234, 243, 248)');
        await page.screenshot({ path: test.info().outputPath(`${portal}-photo-preview-dark.png`), fullPage: true });
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        await expect(edit).toBeFocused();
        await page.getByLabel('Choose profile picture', { exact: true }).setInputFiles({ name: 'invalid.txt', mimeType: 'text/plain', buffer: Buffer.from('not an image') });
        await expect(page.getByText('Choose a JPG, PNG or WebP image up to 5 MB.', { exact: true }).first()).toBeVisible();
        await expect(dialog).not.toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        expect(errors).toEqual([]);
    });
}
