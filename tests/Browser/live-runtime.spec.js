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
    const toggle = page.getByRole('button', { name: 'Toggle navigation', exact: true });
    const navigation = page.getByRole('navigation', { name: 'Mobile homepage navigation' });
    await toggle.click();
    await expect(navigation).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Escape');
    await expect(navigation).toBeHidden();
    expect(errors).toEqual([]);
});
