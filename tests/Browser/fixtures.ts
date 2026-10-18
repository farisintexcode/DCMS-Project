import { expect, test as base, type Page } from '@playwright/test';

let nextAuthenticationWindow = 0;

// Keep shared synthetic accounts below the real five-logins/minute limit without intercepting application requests.
export const test = base.extend<{ pacedAuthentication: void }>({
    pacedAuthentication: [async ({}, use) => {
        const wait = Math.max(0, nextAuthenticationWindow - Date.now());
        if (wait > 0) await new Promise(resolve => setTimeout(resolve, wait));
        nextAuthenticationWindow = Date.now() + 16000;
        await use();
    }, { auto: true }],
});
export { expect, type Page };
