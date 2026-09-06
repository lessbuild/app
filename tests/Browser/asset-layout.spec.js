const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const fixtures = fs.mkdtempSync(path.join(os.tmpdir(), 'buildpusher-asset-layout-'));
const screens = ['landing', 'login', 'dashboard', 'configuration-create', 'configuration-review', 'configuration-receipt'];
const widths = [320, 390, 768, 1440];
const contentTypes = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.json': 'application/json' };

/** Render fixtures through Laravel's testing connection and preserve live application state. */
test.beforeAll(() => {
    execFileSync(process.env.BROWSER_PHP_BINARY || 'php', [
        'vendor/bin/phpunit', '--no-progress', 'tests/Browser/fixtures/AssetLayoutFixtureTest.php',
    ], { cwd: root, env: { ...process.env, BROWSER_FIXTURE_DIRECTORY: fixtures }, timeout: 120_000 });
});

test.afterAll(() => fs.rmSync(fixtures, { recursive: true, force: true }));

/** Fulfill every request locally so fixture actions can never contact real services. */
async function serveFixtures(page) {
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
    const stylesheet = `/build/${manifest['resources/css/app.css'].file}`;
    const alpine = `/build/${manifest['resources/js/alpine.js'].file}`;
    await page.route('**/*', async (route) => {
        const pathname = new URL(route.request().url()).pathname;
        if (route.request().method() !== 'GET') return route.fulfill({ status: 204, body: '' });
        if (screens.includes(pathname.slice(1))) {
            let html = fs.readFileSync(path.join(fixtures, `${pathname.slice(1)}.html`), 'utf8');
            const script = /\/livewire(?:-[^/]+)?\/livewire/.test(html) ? '' : `<script type="module" src="${alpine}"></script>`;
            html = html.replace('</head>', `<link rel="stylesheet" href="${stylesheet}">${script}</head>`);
            return route.fulfill({ contentType: 'text/html', body: html });
        }
        const file = /^\/livewire(?:-[^/]+)?\/livewire/.test(pathname)
            ? path.join(root, 'vendor/livewire/livewire/dist/livewire.js')
            : path.join(root, 'public', pathname);
        if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
            return route.fulfill({ status: 204, body: '' });
        }
        return route.fulfill({ contentType: contentTypes[path.extname(file)] || 'text/plain', body: fs.readFileSync(file) });
    });
}

for (const colorScheme of ['light', 'dark']) {
    for (const width of widths) {
        test(`${colorScheme} at ${width}px: built assets preserve layouts and navigation`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.emulateMedia({ colorScheme });
            await serveFixtures(page);
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));

            for (const screen of screens) {
                await page.goto(`http://buildpusher.test/${screen}`, { waitUntil: 'networkidle' });
                if (screen !== 'landing') expect(await page.evaluate(() => typeof window.Livewire)).toBe('object');
                await expect(page.locator('body')).toHaveCSS('background-color', colorScheme === 'dark' ? 'rgb(31, 41, 55)' : 'rgb(255, 255, 255)');
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), screen).toBe(true);
                const primaryText = page.locator('.text-primary').first();
                await expect(primaryText).toHaveCSS('color', colorScheme === 'dark' ? 'rgb(156, 163, 175)' : 'rgb(75, 85, 99)');
                if (screen === 'login') {
                    await expect(page.locator('#email')).toHaveCSS('border-top-width', '1px');
                    await expect(page.locator('#email')).toHaveCSS('background-color', colorScheme === 'dark' ? 'rgb(31, 41, 55)' : 'rgb(255, 255, 255)');
                }
                if (screen.startsWith('configuration')) {
                    const action = screen === 'configuration-review'
                        ? page.getByRole('button', { name: 'Apply reviewed configuration' })
                        : screen === 'configuration-receipt'
                            ? page.getByRole('button', { name: 'Cancel pending deployment' })
                            : page.getByRole('button', { name: 'Create review' });
                    await action.evaluate((element) => element.scrollIntoView({ block: 'center', behavior: 'instant' }));
                    await expect(action).toBeInViewport();
                    expect(await action.evaluate((element) => {
                        const rect = element.getBoundingClientRect();
                        const top = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
                        return element === top || element.contains(top);
                    }), `${screen} action must not sit behind the footer`).toBe(true);
                }
                if (screen === 'dashboard') {
                    const override = colorScheme === 'dark' ? 'light' : 'dark';
                    await page.evaluate((theme) => document.documentElement.classList.add(theme), override);
                    await expect(page.locator('body')).toHaveCSS('background-color', override === 'dark' ? 'rgb(31, 41, 55)' : 'rgb(255, 255, 255)');
                    await page.evaluate((theme) => document.documentElement.classList.remove(theme), override);
                    const toggle = page.getByRole('button', { name: 'Toggle navigation', exact: true });
                    const menu = page.locator('#primary-navigation');
                    if (width < 1024) {
                        const footer = page.locator('nav.fixed.inset-x-0.bottom-0');
                        await expect(footer).toBeVisible();
                        const bounds = await footer.boundingBox();
                        expect(bounds.x).toBe(0);
                        expect(bounds.width).toBe(width);
                        expect(bounds.y + bounds.height).toBe(900);
                        await toggle.click();
                        await expect(menu).toBeVisible();
                        await expect(page.locator('html')).toHaveCSS('overflow', 'hidden');
                        await page.keyboard.press('Escape');
                        await expect(menu).toBeHidden();
                        await expect(toggle).toBeFocused();
                    } else {
                        await expect(page.locator('#desktop-navigation')).toBeVisible();
                        await expect(toggle).toBeHidden();
                    }
                    await page.keyboard.press('Control+k');
                    await expect(page.getByRole('dialog', { name: 'Command palette' })).toBeVisible();
                    await expect(page.locator('#command-palette-query')).toBeFocused();
                    await page.keyboard.press('Escape');
                }
                if (width === 390) await page.screenshot({ path: test.info().outputPath(`${screen}.png`), fullPage: true });
            }
            expect(errors).toEqual([]);
        });
    }
}
