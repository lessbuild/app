const { test, expect } = require('@playwright/test');

const viewports = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'desktop', width: 1440, height: 1000 },
];

for (const viewport of viewports) {
    test(`${viewport.name}: shared navigation groups preserve access and active state`, async ({ page, baseURL }) => {
        await page.setViewportSize(viewport);
        await page.goto(new URL('/login', baseURL).toString());
        await page.locator('#email').fill('ncorkish@icloud.com');
        await page.locator('#password').fill('password');
        await Promise.all([
            page.waitForURL((url) => url.pathname === '/home'),
            page.getByRole('button', { name: 'Login' }).click(),
        ]);

        const navigation = page.locator(viewport.width < 1024 ? '#primary-navigation' : '#desktop-navigation');
        if (viewport.width < 1024) {
            await page.getByRole('button', { name: 'Toggle navigation', exact: true }).click();
            await expect(navigation).toBeVisible();
        } else {
            await expect(navigation).toBeVisible();
        }

        await expect(navigation.getByRole('link', { name: 'Applications', exact: true })).toBeVisible();
        if (viewport.width < 1024) {
            await navigation.locator('summary').filter({ hasText: 'Templates' }).click();
            for (const mobileLink of ['Deployments', 'Repositories', 'Recipes', 'Gallery', 'Billing', 'Costs and usage', 'Account', 'Settings']) {
                await expect(navigation.getByRole('link', { name: mobileLink, exact: true })).toBeVisible();
            }
            for (const mergedLink of ['Template library', 'Billing and usage', 'Account and security']) {
                await expect(navigation.getByRole('link', { name: mergedLink, exact: true })).toHaveCount(0);
            }
        } else {
            await expect(navigation.getByRole('link', { name: 'Template library', exact: true })).toBeVisible();
            await expect(navigation.getByRole('link', { name: 'Billing and usage', exact: true })).toBeVisible();
            await expect(navigation.getByRole('link', { name: 'Account and security', exact: true })).toBeVisible();
            for (const mergedLink of ['Deployments', 'Repositories', 'Recipes', 'Gallery', 'Billing', 'Costs and usage', 'Settings']) {
                await expect(navigation.getByRole('link', { name: mergedLink, exact: true })).toHaveCount(0);
            }
        }
        await expect(navigation.getByRole('navigation', { name: 'Build and release', exact: true })).toBeVisible();
        await expect(navigation.getByRole('navigation', { name: 'Workspace', exact: true })).toBeVisible();

        await page.goto(new URL('/projects', baseURL).toString(), { waitUntil: 'domcontentloaded' });
        if (viewport.width < 1024) {
            await page.getByRole('button', { name: 'Toggle navigation', exact: true }).click();
        }
        await expect(navigation.getByRole('link', { name: 'Applications', exact: true })).toHaveAttribute('aria-current', 'page');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2)).toBe(true);
    });
}
