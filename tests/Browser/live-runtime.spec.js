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

    await toggle.click();
    await expect(drawer).toBeVisible();
    expect(await drawer.evaluate(element => element.contains(document.activeElement))).toBe(true);
    await expect.poll(() => page.evaluate(() => document.body.classList.contains('overflow-hidden'))).toBe(true);

    for (let index = 0; index < 16; index++) {
        await page.keyboard.press('Tab');
        expect(await drawer.evaluate(element => element.contains(document.activeElement))).toBe(true);
    }

    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(toggle).toBeFocused();
    await expect.poll(() => page.evaluate(() => document.body.classList.contains('overflow-hidden'))).toBe(false);

    await toggle.click();
    await expect(drawer).toBeVisible();
    await page.setViewportSize({ width: 768, height: 844 });
    await expect(drawer).toBeHidden();
    await expect(page.getByRole('navigation', { name: 'Primary navigation' }).getByRole('link').first()).toBeFocused();
});
