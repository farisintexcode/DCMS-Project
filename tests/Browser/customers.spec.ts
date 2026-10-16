import { expect, test } from '@playwright/test';

test('customer categories create edit search and history work on desktop and small screens', async ({ page }) => {
    const email = process.env.DCMS_E2E_ADMIN_EMAIL;
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!email || !password, 'Provision isolated synthetic Admin/client fixtures.');
    test.setTimeout(90000);
    const suffix = test.info().project.name;
    const reference = `E2E-${suffix}`;
    const category = `VIP-${suffix}`;
    const identifier = suffix === 'rider-mobile' ? '002233445566' : '001122334455';
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email!);
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin/customers/categories');
    await page.getByLabel('Client', { exact: true }).selectOption({ label: 'Synthetic Client' });
    await page.getByLabel('Category code', { exact: true }).fill(category);
    await page.getByLabel('Category name', { exact: true }).fill(category);
    await page.getByRole('button', { name: 'Save category', exact: true }).click();
    await expect(page.getByRole('heading', { name: category, exact: true })).toBeVisible();
    await page.goto('/admin/customers/create');
    await page.getByLabel('Client', { exact: true }).selectOption({ label: 'Synthetic Client' });
    await page.getByLabel('Customer reference', { exact: true }).fill(reference);
    await page.getByLabel('Customer name', { exact: true }).fill(`Aisyah ${suffix}`);
    await page.getByLabel('Identifier (optional)', { exact: true }).fill(identifier);
    await page.getByRole('button', { name: 'Add contact', exact: true }).click();
    await page.getByLabel('Contact type', { exact: true }).selectOption('email');
    await page.getByLabel('Contact value', { exact: true }).fill(`${suffix}@example.test`);
    await page.getByLabel(category, { exact: true }).check();
    await page.getByRole('button', { name: 'Save customer', exact: true }).click();
    await expect(page.getByRole('heading', { name: `Aisyah ${suffix}`, exact: true })).toBeVisible();
    await expect(page.locator('main')).not.toContainText(identifier);
    await page.getByRole('link', { name: 'Edit customer', exact: true }).click();
    await page.getByLabel('Customer name', { exact: true }).fill(`Updated Aisyah ${suffix}`);
    await page.getByLabel('Contact value', { exact: true }).fill(`updated-${suffix}@example.test`);
    await page.getByLabel('Status', { exact: true }).selectOption('inactive');
    await page.getByRole('button', { name: 'Save customer', exact: true }).click();
    await expect(page.getByRole('heading', { name: `Updated Aisyah ${suffix}`, exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Change History', exact: true }).click();
    await expect(page.getByText('Contact observations', { exact: true })).toBeVisible();
    await expect(page.locator('main')).toContainText(`updated-${suffix}@example.test`);
    await expect(page.locator('main')).toContainText('Superseded');
    if (suffix === 'rider-mobile') {
        for (const width of [320, 430]) {
            await page.setViewportSize({ width, height: 740 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        }
    }
    await page.screenshot({ path: test.info().outputPath('customer-history.png'), fullPage: true });
    await page.getByRole('link', { name: 'Back to customers', exact: true }).click();
    await page.getByLabel('Search by', { exact: true }).selectOption('name');
    await expect(page.getByText('Use at least 3 characters per name token.', { exact: false })).toBeVisible();
    const searchResponse = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/livewire'));
    await page.getByLabel('Search customers', { exact: true }).fill('Ais');
    await searchResponse;
    await expect(page.getByText('Loading customers…', { exact: true })).toBeHidden();
    await expect(page.getByText('Foreign client customer', { exact: true })).toHaveCount(0);
    await expect(page.getByText(`Updated Aisyah ${suffix}`, { exact: true }).filter({ visible: true }).first()).toBeVisible();
    await expect(page.locator('main')).toContainText(reference);
    await page.screenshot({ path: test.info().outputPath('customer-list.png'), fullPage: true });
});
