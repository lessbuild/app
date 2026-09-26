const { test, expect } = require('@playwright/test');

// Opt in against the Core host of an isolated runtime or deployed installation.
const origin = process.env.BROWSER_CORE_ORIGIN;
test.skip(!origin, 'Set BROWSER_CORE_ORIGIN to the Core host to check pricing.');

test.beforeEach(async ({ page }) => {
    await page.route('**/*', route => route.request().method() === 'GET'
        ? route.continue()
        : route.abort());
});

test('four-digit annual prices switch to monthly prices without JavaScript errors', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(new URL('/pricing', origin).href, { waitUntil: 'networkidle' });

    const panel = page.getByRole('tabpanel', { name: 'Deployer', exact: true });
    const unlimited = panel.locator('[data-pricing-plan="deployer-unlimited"]');
    await expect(unlimited.getByText('$1,990', { exact: true })).toBeVisible();
    await panel.getByRole('button', { name: 'Monthly', exact: true }).click();
    await expect(unlimited.getByText('$199', { exact: true })).toBeVisible();
    await expect(unlimited.getByText('/month', { exact: true })).toBeVisible();
    await panel.getByRole('button', { name: /Yearly/ }).click();
    await expect(unlimited.getByText('$1,990', { exact: true })).toBeVisible();
    await expect(unlimited.getByText('/year', { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('product pricing panels support keyboard navigation and fit a mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(new URL('/pricing', origin).href, { waitUntil: 'networkidle' });

    const deployer = page.getByRole('tab', { name: 'Deployer', exact: true });
    const monitor = page.getByRole('tab', { name: 'Monitor', exact: true });
    const analytics = page.getByRole('tab', { name: 'Analytics', exact: true });
    await deployer.focus();
    await deployer.press('ArrowRight');
    await expect(monitor).toBeFocused();
    await expect(monitor).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tabpanel', { name: 'Monitor', exact: true })).toBeVisible();
    await expect(page.locator('#pricing-panel-deployer')).toBeHidden();
    await monitor.press('End');
    await expect(analytics).toBeFocused();
    await expect(page.getByRole('tabpanel', { name: 'Analytics', exact: true })).toBeVisible();
    await expect(page.locator('#pricing-panel-monitor')).toBeHidden();
    await analytics.press('Home');
    await expect(deployer).toBeFocused();
    await expect(page.getByRole('tabpanel', { name: 'Deployer', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});
