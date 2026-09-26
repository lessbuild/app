const { test, expect } = require('@playwright/test');
const path = require('node:path');

test('Signal workspace search renders safe cross-product results and restores keyboard focus', async ({ page }) => {
    let requestedQuery = null;

    await page.route('https://signal.test/workspaces/*/search**', async (route) => {
        requestedQuery = new URL(route.request().url()).searchParams.get('q');
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                query: requestedQuery,
                groups: [{
                    product: 'monitor',
                    label: 'Monitor · Incidents',
                    count: 2,
                    results: [
                        { type: 'Incident', title: 'Storefront API latency', subtitle: 'Open', url: 'https://monitor.test/incidents/27' },
                        { type: 'Incident', title: '<img src=x onerror=alert(1)>', subtitle: null, url: 'https://monitor.test/incidents/28' },
                    ],
                }],
                unavailable: [{ product: 'analytics', label: 'Analytics' }],
            }),
        });
    });

    await page.setContent(`
        <button type="button" data-signal-command-open aria-controls="signal-command-palette">Jump to</button>
        <dialog id="signal-command-palette" data-signal-command-palette data-signal-command-search-url="https://signal.test/workspaces/12/search" aria-label="Search this workspace">
            <label for="signal-query">Search workspace resources</label>
            <input id="signal-query" type="search" data-signal-command-input>
            <nav class="ui-command-list" aria-label="Quick actions">
                <section class="ui-command-section" data-signal-command-section>
                    <p class="ui-command-heading">Quick actions</p>
                    <div class="ui-command-row" data-signal-command-row>
                        <a href="/dashboard" class="ui-command-item" data-signal-command-item data-search="dashboard">Dashboard</a>
                    </div>
                </section>
                <div data-signal-command-dynamic-results class="contents"></div>
            </nav>
            <p data-signal-command-empty hidden>No matching pages or actions.</p>
            <p data-signal-command-status role="status" aria-live="polite"></p>
        </dialog>
    `);

    await page.addScriptTag({ path: path.resolve(__dirname, '../../resources/js/signal-topbar.js') });

    const trigger = page.getByRole('button', { name: 'Jump to' });
    const dialog = page.getByRole('dialog', { name: 'Search this workspace' });
    const input = page.getByRole('searchbox', { name: 'Search workspace resources' });
    await trigger.click();
    await expect(dialog).toBeVisible();
    await input.fill('Storefront');

    const result = page.getByRole('link', { name: /Storefront API latency/ });
    await expect(result).toBeVisible();
    await expect(page.locator('[data-signal-command-section]')).toBeHidden();
    await expect(page.locator('[data-signal-command-unavailable]')).toContainText('Analytics');
    await expect(page.locator('[data-signal-command-status]')).toContainText('2 results across 1 section');
    await expect(page.locator('[data-signal-command-dynamic-item] img')).toHaveCount(0);
    await expect(page.locator('[data-signal-command-dynamic-item]').nth(1)).toContainText('<img src=x onerror=alert(1)>');
    expect(requestedQuery).toBe('Storefront');

    await input.press('ArrowDown');
    await expect(result).toBeFocused();

    await input.fill('d');
    await expect(page.locator('[data-signal-command-section]')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Dashboard' })).toBeVisible();
    await expect(page.locator('[data-signal-command-dynamic-item]')).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();

    await page.evaluate(() => {
        document.querySelector('a[data-signal-command-item]')?.addEventListener('click', (event) => event.preventDefault());
    });
    await trigger.click();
    await expect(dialog).toBeVisible();
    await page.getByRole('link', { name: 'Dashboard' }).click();
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
});
