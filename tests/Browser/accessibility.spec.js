const { test, expect } = require('@playwright/test');

const viewports = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'desktop', width: 1440, height: 1000 },
];

for (const viewport of viewports) {
    test(`${viewport.name}: keyboard and screen-reader essentials`, async ({ page, baseURL }) => {
        await page.setViewportSize(viewport);
        await page.goto(new URL('/request-access', baseURL).toString());
        if (new URL(page.url()).pathname === '/request-access') {
            await expect(page.getByRole('heading', { name: 'Request access', exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Send access request' })).toBeVisible();
            await expect(page.locator('input[name="email"]')).toHaveAttribute('autocomplete', 'email');
        }
        await page.goto(new URL('/login', baseURL).toString());
        await page.locator('#email').fill('ncorkish@icloud.com');
        await page.locator('#password').fill('password');
        await Promise.all([
            page.waitForURL((url) => url.pathname === '/home'),
            page.getByRole('button', { name: 'Login' }).click(),
        ]);

        const issues = await page.evaluate(() => {
            const visible = (element) => Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
            const accessibleName = (element) => element.getAttribute('aria-label')
                || (element.getAttribute('aria-labelledby') && document.getElementById(element.getAttribute('aria-labelledby'))?.textContent)
                || element.textContent?.trim()
                || element.getAttribute('title');
            const ids = [...document.querySelectorAll('[id]')].map((element) => element.id);
            const duplicates = [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))];
            const unnamedActions = [...document.querySelectorAll('a[href], button')].filter((element) => visible(element) && !accessibleName(element));
            const unnamedFields = [...document.querySelectorAll('input:not([type=hidden]), select, textarea')].filter((element) => {
                if (!visible(element)) return false;
                return !element.getAttribute('aria-label') && !element.getAttribute('aria-labelledby')
                    && !(element.id && document.querySelector(`label[for="${CSS.escape(element.id)}"]`))
                    && !element.closest('label');
            });
            return { duplicates, unnamedActions: unnamedActions.length, unnamedFields: unnamedFields.length };
        });
        expect(issues).toEqual({ duplicates: [], unnamedActions: 0, unnamedFields: 0 });
        await expect(page.locator('html')).toHaveAttribute('lang', /.+/);
        await expect(page.locator('#main-content')).toHaveCount(1);

        await page.keyboard.press('Control+k');
        const palette = page.getByRole('dialog', { name: 'Command palette' });
        await expect(palette).toBeVisible();
        await expect(page.locator('#command-palette-query')).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(palette).toBeHidden();
        if (viewport.width >= 640) await expect(page.getByRole('button', { name: /Search and navigate/ })).toBeFocused();

        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => document.activeElement !== document.body)).toBe(true);
    });
}
