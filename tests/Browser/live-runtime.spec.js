const { test, expect } = require('@playwright/test');

// Opt in against a running installation: unlike the fixture layout suite, these
// checks fetch scripts through the web server and its actual Laravel route cache.
const origin = process.env.BROWSER_LIVE_ORIGIN;
test.skip(!origin, 'Set BROWSER_LIVE_ORIGIN to check a deployed installation.');

test('served Livewire runtime and mobile public navigation work', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => route.request().method() === 'GET'
        ? route.continue()
        : route.abort());
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(new URL('/login', origin).href, { waitUntil: 'networkidle' });

    const source = await page.locator('script[src*="/livewire"]').getAttribute('src');
    const scriptUrl = new URL(source, origin);
    expect(scriptUrl.origin).toBe(new URL(origin).origin);
    const script = await page.request.get(scriptUrl.href);
    expect(script.status()).toBe(200);
    expect(script.headers()['content-type']).toContain('javascript');
    await expect.poll(() => page.evaluate(() => typeof window.Livewire)).toBe('object');
    await expect.poll(() => page.evaluate(() => typeof window.Alpine)).toBe('object');

    await page.goto(new URL('/', origin).href, { waitUntil: 'networkidle' });
    const toggle = page.getByRole('button', { name: 'Open navigation', exact: true });
    const navigation = page.getByRole('navigation', { name: 'Mobile homepage navigation' });
    await toggle.click();
    await expect(navigation).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Escape');
    await expect(navigation).toBeHidden();

    await page.goto(new URL('/docs', origin).href, { waitUntil: 'networkidle' });
    const drawerEntry = page.locator('script[src*="signal-drawer"]');
    await expect(drawerEntry).toHaveCount(1);
    const drawerScriptUrl = new URL(await drawerEntry.getAttribute('src'), origin);
    expect((await page.request.get(drawerScriptUrl.href)).status()).toBe(200);
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    await expect(page.locator('#navbarCollapse')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#navbarCollapse')).toBeHidden();
    expect(errors).toEqual([]);
});

test('public mobile navigation traps focus and restores it when closed', async ({ page }) => {
    await page.route('**/*', route => route.request().method() === 'GET'
        ? route.continue()
        : route.abort());
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(new URL('/', origin).href, { waitUntil: 'networkidle' });

    const toggle = page.locator('#navbarToggler');
    const drawer = page.locator('#navbarCollapse');
    const firstFocusable = drawer.locator('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])').first();
    const lastFocusable = drawer.locator('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])').last();

    await toggle.click();
    await expect(drawer).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(await drawer.evaluate(element => element.contains(document.activeElement))).toBe(true);
    await expect.poll(() => page.evaluate(() => document.body.classList.contains('overflow-hidden'))).toBe(true);

    await page.keyboard.press('Shift+Tab');
    await expect(lastFocusable).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(firstFocusable).toBeFocused();

    for (let index = 0; index < 16; index++) {
        await page.keyboard.press('Tab');
        expect(await drawer.evaluate(element => element.contains(document.activeElement))).toBe(true);
    }

    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(toggle).toBeFocused();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect.poll(() => page.evaluate(() => document.body.classList.contains('overflow-hidden'))).toBe(false);

    await toggle.click();
    await expect(drawer).toBeVisible();
    await page.setViewportSize({ width: 768, height: 844 });
    await expect(drawer).toBeHidden();
    await expect(page.locator('[data-desktop-navigation] a[href]').first()).toBeFocused();

    await page.setViewportSize({ width: 390, height: 844 });
    await toggle.click();
    await drawer.locator('[data-mobile-toggle]').last().click();
    await expect(drawer).toBeHidden();
    await expect(toggle).toBeFocused();

    await toggle.click();
    await drawer.locator('[data-mobile-toggle]').first().click({ position: { x: 5, y: 400 } });
    await expect(drawer).toBeHidden();
    await expect(toggle).toBeFocused();
});

test('public landing renders and operates the Signal FAQ and CTA blocks', async ({ page }) => {
    await page.route('**/*', route => route.request().method() === 'GET'
        ? route.continue()
        : route.abort());
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(new URL('/', origin).href, { waitUntil: 'networkidle' });

    const faq = page.locator('#questions details').first();
    const summary = faq.locator('summary');
    await expect(faq).toHaveClass(/rounded-card/);
    await expect(summary).toBeVisible();
    await summary.press('Enter');
    await expect(faq).toHaveAttribute('open', '');
    await summary.press('Space');
    await expect(faq).not.toHaveAttribute('open', '');

    const callToAction = page.locator('#main-content .ui-emphasis').last();
    await expect(callToAction).toHaveClass(/rounded-panel/);
    await expect(callToAction).toHaveClass(/sm:p-10/);
    await expect(callToAction.locator('a.ui-btn-primary.ui-btn-lg')).toBeVisible();

    const footer = page.getByRole('contentinfo').last();
    const footerNavigation = footer.getByRole('navigation', { name: 'Footer navigation' });
    await expect(footer).toContainText('Keep shipping clearly');
    await expect(footerNavigation.getByRole('link', { name: 'Capabilities' })).toHaveAttribute('href', '#features');
    await expect(footerNavigation.getByRole('link', { name: 'Privacy' })).toBeVisible();
    await expect(footer.getByRole('link', { name: 'Open the workspace' })).toHaveAttribute('href', /\/login$/);
});
