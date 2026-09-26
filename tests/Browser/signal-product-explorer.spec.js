const { test, expect } = require('@playwright/test');
const path = require('node:path');

test('Buildpusher product explorer supports pointer and keyboard navigation', async ({ page }) => {
    await page.setContent(`<!doctype html>
        <html lang="en"><body>
            <section data-product-explorer>
                <nav data-product-tabs aria-label="Explore Buildpusher products">
                    <a href="#panel-deploy" data-product-tab="deploy">Deploy</a>
                    <a href="#panel-monitor" data-product-tab="monitor">Monitor</a>
                </nav>
                <article id="panel-deploy" data-product-panel="deploy">Deploy content</article>
                <article id="panel-monitor" data-product-panel="monitor">Monitor content</article>
            </section>
        </body></html>`);

    await expect(page.getByText('Deploy content')).toBeVisible();
    await expect(page.getByText('Monitor content')).toBeVisible();

    await page.addScriptTag({
        path: path.resolve(__dirname, '../../resources/js/signal-marketing.js'),
    });

    const deployTab = page.getByRole('tab', { name: 'Deploy' });
    const monitorTab = page.getByRole('tab', { name: 'Monitor' });
    const deployPanel = page.getByRole('tabpanel', { name: 'Deploy' });
    const monitorPanel = page.getByRole('tabpanel', { name: 'Monitor' });

    await expect(deployTab).toHaveAttribute('aria-selected', 'true');
    await expect(deployPanel).toBeVisible();
    await expect(monitorPanel).toBeHidden();

    await monitorTab.click();
    await expect(monitorTab).toHaveAttribute('aria-selected', 'true');
    await expect(monitorPanel).toBeVisible();
    await expect(deployPanel).toBeHidden();

    await monitorTab.press('ArrowLeft');
    await expect(deployTab).toBeFocused();
    await expect(deployTab).toHaveAttribute('aria-selected', 'true');
    await expect(deployPanel).toBeVisible();
});

test('Buildpusher Analytics preview changes its illustrative date range accessibly', async ({ page }) => {
    await page.setContent(`<!doctype html>
        <html lang="en"><body>
            <section data-product-analytics>
                <span data-product-analytics-period>Last 30 days</span>
                <span data-product-visitors>1,248</span>
                <span data-product-change>↑ 12%</span>
                <p data-product-analytics-summary>Illustrative estimate for the last 30 days.</p>
                <p data-product-analytics-status role="status" aria-live="polite">Showing illustrative traffic for the last 30 days.</p>
                <label>Analytics sample date range
                    <select data-product-analytics-range disabled>
                        <option value="30" selected data-visitors="1,248" data-change="↑ 12%" data-label="Last 30 days" data-summary="Illustrative estimate for the last 30 days." data-announcement="Showing illustrative traffic for the last 30 days." data-chart-label="Illustrative traffic estimate trend for the last 30 days" data-chart-path="M0 91 24 71 49 78 73 61 98 69 123 51 147 57 172 42 196 52 221 34 246 44 270 27 295 38 319 24 344 32 369 15 393 24 418 10 443 18 468 3 500 5">Last 30 days</option>
                        <option value="7" data-visitors="312" data-change="↑ 8%" data-label="Last 7 days" data-summary="Illustrative estimate for the last 7 days." data-announcement="Showing illustrative traffic for the last 7 days." data-chart-label="Illustrative traffic estimate trend for the last 7 days" data-chart-path="M0 82 45 65 90 72 135 48 180 55 225 38 270 45 315 24 360 33 405 19 450 26 500 7">Last 7 days</option>
                    </select>
                </label>
                <svg data-product-traffic-chart aria-label="Illustrative traffic estimate trend for the last 30 days">
                    <path data-product-traffic-fill></path>
                    <path data-product-traffic-line></path>
                </svg>
            </section>
        </body></html>`);

    await page.addScriptTag({
        path: path.resolve(__dirname, '../../resources/js/signal-marketing.js'),
    });

    const range = page.getByLabel('Analytics sample date range');
    const line = page.locator('[data-product-traffic-line]');

    await expect(range).toBeEnabled();
    await expect(page.locator('[data-product-visitors]')).toHaveText('1,248');
    await range.selectOption('7');
    await expect(page.locator('[data-product-visitors]')).toHaveText('312');
    await expect(page.locator('[data-product-change]')).toHaveText('↑ 8%');
    await expect(page.locator('[data-product-analytics-period]')).toHaveText('Last 7 days');
    await expect(page.locator('[data-product-analytics-status]')).toHaveText('Showing illustrative traffic for the last 7 days.');
    await expect(page.locator('[data-product-traffic-chart]')).toHaveAttribute('aria-label', 'Illustrative traffic estimate trend for the last 7 days');
    await expect(line).toHaveAttribute('d', 'M0 82 45 65 90 72 135 48 180 55 225 38 270 45 315 24 360 33 405 19 450 26 500 7');
});
