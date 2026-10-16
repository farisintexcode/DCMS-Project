import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 2,
    expect: { timeout: 15000 },
    retries: process.env.CI ? 1 : 0,
    reporter: 'list',
    use: { baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000', trace: 'retain-on-failure' },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'rider-mobile', use: { ...devices['iPhone SE'], defaultBrowserType: 'chromium' } },
    ],
});
