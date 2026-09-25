const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const fixtures = fs.mkdtempSync(path.join(os.tmpdir(), 'buildpusher-signal-theme-'));
const products = ['core', 'deployer', 'monitor', 'analytics', 'public', 'auth'];
const viewports = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'compact desktop', width: 1279, height: 900 },
    { name: 'desktop', width: 1280, height: 900 },
];
const contentTypes = {
    '.css': 'text/css',
    '.js': 'text/javascript',
    '.json': 'application/json',
    '.svg': 'image/svg+xml',
    '.webmanifest': 'application/manifest+json',
};

test.beforeAll(() => {
    execFileSync(process.env.BROWSER_PHP_BINARY || 'php', [
        'vendor/bin/phpunit', '--no-progress', 'tests/Browser/fixtures/SignalThemeTokenFixtureTest.php',
    ], {
        cwd: root,
        env: { ...process.env, BROWSER_FIXTURE_DIRECTORY: fixtures },
        timeout: 120_000,
    });
});

test.afterAll(() => fs.rmSync(fixtures, { recursive: true, force: true }));

test('one shared Signal token change reaches every product, public, and auth document', async ({ page }) => {
    await page.route('**/*', async (route) => {
        const request = route.request();

        if (request.method() !== 'GET') {
            return route.fulfill({ status: 204, body: '' });
        }

        const url = new URL(request.url());

        if (url.pathname.startsWith('/theme-token-demo/')) {
            const product = url.pathname.split('/').filter(Boolean).at(-1);
            const fixturePath = path.join(fixtures, `${product}.html`);

            if (products.includes(product) && fs.existsSync(fixturePath)) {
                return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(fixturePath) });
            }

            return route.fulfill({ status: 404, body: '' });
        }

        const assetPath = path.resolve(root, 'public', `.${url.pathname}`);
        const publicPath = path.join(root, 'public') + path.sep;

        if (! assetPath.startsWith(publicPath) || ! fs.existsSync(assetPath) || ! fs.statSync(assetPath).isFile()) {
            return route.fulfill({ status: 404, body: '' });
        }

        return route.fulfill({
            contentType: contentTypes[path.extname(assetPath)] || 'application/octet-stream',
            body: fs.readFileSync(assetPath),
        });
    });

    const stylesheetPaths = new Set();

    await page.addInitScript(() => {
        localStorage.setItem('buildpusher-appearance', 'dark');
        localStorage.setItem('buildpusher-preset', 'ocean');
        localStorage.setItem('buildpusher-palette', 'blue');
        localStorage.setItem('buildpusher-density', 'compact');
        localStorage.setItem('buildpusher-corners', 'rounded');
        localStorage.setItem('buildpusher-font', 'editorial');
        localStorage.setItem('buildpusher-token-ui-primary', '#0ea5e9');
    });

    await page.goto('http://signal-theme.test/theme-token-demo/deployer', { waitUntil: 'load' });

    const migratedTheme = await page.evaluate(() => ({
        namespace: document.documentElement.dataset.storageNamespace,
        appearance: document.documentElement.dataset.appearance,
        palette: document.documentElement.dataset.palette,
        density: document.documentElement.dataset.density,
        corners: document.documentElement.dataset.corners,
        preset: document.documentElement.dataset.preset,
        font: document.documentElement.dataset.font,
        legacyPrimaryOverride: document.documentElement.style.getPropertyValue('--ui-primary'),
    }));

    expect(migratedTheme.namespace).toBe('buildpusher-signal');
    expect(migratedTheme.appearance).toBe('dark');
    expect(migratedTheme.palette).toBe('graphite');
    expect(migratedTheme.density).toBe('comfortable');
    expect(migratedTheme.corners).toBe('subtle');
    expect(migratedTheme.preset).toBe('modern');
    expect(migratedTheme.font).toBe('system');
    expect(migratedTheme.legacyPrimaryOverride).toBe('');

    const theme = new URLSearchParams({
        theme_appearance: 'light',
        theme_palette: 'rose',
        theme_corners: 'rounded',
        theme_density: 'compact',
        theme_font: 'editorial',
    });

    for (const viewport of viewports) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });

        for (const product of products) {
            await page.goto(`http://signal-theme.test/theme-token-demo/${product}?${theme}`, { waitUntil: 'load' });

            const rootElement = page.locator('html');
            await expect(rootElement).toHaveAttribute('data-palette', 'rose');
            await expect(rootElement).toHaveAttribute('data-corners', 'rounded');
            await expect(rootElement).toHaveAttribute('data-density', 'compact');
            await expect(rootElement).toHaveAttribute('data-font', 'editorial');
            await expect(page.getByRole('heading', { name: 'Shared theme proof' })).toBeVisible();
            await expect(page.locator('[data-theme-demo-page-header]')).toHaveAttribute('data-page-header', '');
            await expect(page.locator('[data-theme-demo-page-header]')).toHaveCSS('border-bottom-style', 'solid');
            await expect(page.getByRole('button', { name: 'Shared primary action' })).toBeVisible();
            await expect(page.getByLabel('Email address')).toBeVisible();

            const stylesheet = await page.locator('link[rel="stylesheet"][href*="/build/assets/app-"]').getAttribute('href');
            stylesheetPaths.add(new URL(stylesheet).pathname);

            const tokenResult = await page.evaluate(() => {
                const readColorToken = (token) => {
                    const probe = document.createElement('span');
                    probe.style.backgroundColor = `var(${token})`;
                    document.body.append(probe);
                    const color = getComputedStyle(probe).backgroundColor;
                    probe.remove();

                    return color;
                };

                const root = document.documentElement;
                const panel = document.querySelector('[data-theme-demo-panel]');
                const card = document.querySelector('[data-theme-demo-card]');
                const button = document.querySelector('[data-theme-demo-button]');
                const input = document.querySelector('[data-theme-demo-input]');
                const heading = document.querySelector('[data-theme-demo-page-header] h1');

                return {
                    product: root.dataset.product,
                    buttonColor: getComputedStyle(button).backgroundColor,
                    primaryTokenColor: readColorToken('--ui-primary'),
                    panelColor: getComputedStyle(panel).backgroundColor,
                    surfaceTokenColor: readColorToken('--ui-surface'),
                    cardColor: getComputedStyle(card).backgroundColor,
                    mutedSurfaceTokenColor: readColorToken('--ui-surface-muted'),
                    panelRadius: getComputedStyle(panel).borderRadius,
                    cardRadius: getComputedStyle(card).borderRadius,
                    buttonRadius: getComputedStyle(button).borderRadius,
                    inputPaddingTop: Number.parseFloat(getComputedStyle(input).paddingTop),
                    layoutGutter: getComputedStyle(document.querySelector('main')).paddingLeft,
                    headingFont: getComputedStyle(heading).fontFamily,
                    documentWidth: document.documentElement.scrollWidth,
                    viewportWidth: document.documentElement.clientWidth,
                };
            });

            expect(tokenResult.buttonColor).toBe(tokenResult.primaryTokenColor);
            expect(tokenResult.buttonColor).toBe('rgb(225, 29, 72)');
            expect(tokenResult.panelColor).toBe(tokenResult.surfaceTokenColor);
            expect(tokenResult.cardColor).toBe(tokenResult.mutedSurfaceTokenColor);
            expect(tokenResult.panelRadius).toBe('25.6px');
            expect(tokenResult.cardRadius).toBe('19.2px');
            expect(tokenResult.buttonRadius).toBe('16px');
            expect(tokenResult.inputPaddingTop).toBeCloseTo(8.8, 1);
            expect(tokenResult.layoutGutter).toBe(viewport.width < 640 ? '12px' : '20px');
            expect(tokenResult.headingFont).toContain('Georgia');
            expect(tokenResult.documentWidth).toBeLessThanOrEqual(tokenResult.viewportWidth + 2);
            expect(tokenResult.product).toBe(['public', 'auth'].includes(product) ? '' : product);

            if (['core', 'deployer', 'monitor', 'analytics'].includes(product)) {
                const shell = page.locator('header[data-topbar-shell]');
                await expect(shell).toHaveCount(1);
                await expect(shell.locator('nav[aria-label="Products"]')).toHaveCount(1);

                if (viewport.width < 1280) {
                    await expect(shell.locator('nav[aria-label="Products"]')).toBeHidden();
                    const mobileMenu = page.locator('#signal-mobile-product-navigation-drawer');
                    const trigger = shell.getByRole('button', { name: 'Open application navigation' });
                    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
                    await trigger.click();
                    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
                    await expect(mobileMenu).toHaveAttribute('aria-hidden', 'false');
                    await expect(mobileMenu).toBeVisible();
                    const headerBox = await shell.boundingBox();
                    const drawerBox = await mobileMenu.boundingBox();
                    expect(drawerBox.y).toBeCloseTo(headerBox.y + headerBox.height, 0);
                    expect(drawerBox.height).toBeCloseTo(viewport.height - headerBox.y - headerBox.height, 0);
                    await expect(mobileMenu.locator('aside')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
                    const mobileNavigation = page.locator('#signal-mobile-product-navigation');
                    await expect(mobileNavigation).toBeVisible();
                    await expect(mobileNavigation.getByRole('link', { name: 'Dashboard', exact: true })).toHaveAttribute('aria-current', 'page');
                    await page.keyboard.press('Shift+Tab');
                    expect(await mobileMenu.evaluate((drawer) => drawer.contains(document.activeElement))).toBe(true);
                    await page.keyboard.press('Escape');
                    await expect(mobileMenu).toHaveAttribute('aria-hidden', 'true');
                    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
                    await expect(trigger).toBeFocused();
                }

                if (product === 'deployer') {
                    if (viewport.width < 1280) {
                        await expect(page.locator('#signal-mobile-product-navigation-drawer a[href="/theme-token-demo/deployer"]')).toHaveAttribute('aria-current', 'page');
                    } else {
                        await expect(shell.locator('nav[aria-label="Products"]')).toBeVisible();
                        await expect(shell.locator('a[href="/theme-token-demo/deployer"]')).toHaveAttribute('aria-current', 'page');
                    }
                }
            }
        }
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`http://signal-theme.test/theme-token-demo/deployer?${theme}`, { waitUntil: 'load' });

    const sheetTrigger = page.getByRole('button', { name: 'Related environments', exact: true });
    const sheet = page.getByRole('dialog', { name: 'Related environments', exact: true });
    await sheetTrigger.click();
    await expect(sheet).toBeVisible();
    await expect(sheet).toHaveAttribute('aria-hidden', 'false');
    await expect(page.locator('body')).toHaveClass(/overflow-hidden/);
    await expect(sheet.getByRole('button', { name: 'Close Related environments' })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(sheet.getByRole('link', { name: 'Production', exact: true })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(sheet.getByRole('button', { name: 'Close Related environments' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(sheet).toBeHidden();
    await expect(sheetTrigger).toHaveAttribute('aria-expanded', 'false');
    await expect(sheetTrigger).toBeFocused();
    await expect(page.locator('body')).not.toHaveClass(/overflow-hidden/);

    expect([...stylesheetPaths]).toHaveLength(1);
});

test('Core appearance preference overrides stale host storage and saves across product hosts', async ({ page }) => {
    let savedAppearance = 'dark';
    const themeInit = fs.readFileSync(path.join(root, 'resources/js/signal-theme-init.js'), 'utf8');
    const themeController = fs.readFileSync(path.join(root, 'resources/js/signal-theme.js'), 'utf8');

    await page.addInitScript(() => {
        const staleAppearance = location.hostname === 'deployer.signal-theme.test' ? 'light' : 'dark';
        localStorage.setItem('buildpusher-signal-appearance', staleAppearance);
    });

    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());

        if (url.pathname === '/__platform/preferences/theme' && request.method() === 'PUT') {
            savedAppearance = request.postDataJSON().appearance;

            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ appearance: savedAppearance }),
            });
        }

        if (url.pathname === '/theme-preference-demo') {
            const html = `<!doctype html>
                <html lang="en" data-storage-namespace="buildpusher-signal" data-theme-user-appearance="${savedAppearance}" data-default-appearance="system" data-theme-preference-url="/__platform/preferences/theme" data-theme-saving-label="Saving appearance preference." data-theme-saved-label="Appearance preference saved to your account." data-theme-save-failed-label="Appearance could not be saved.">
                    <head><meta name="csrf-token" content="fixture-csrf-token"><script>${themeInit}</script><script>${themeController}</script></head>
                    <body><button type="button" data-theme-toggle>Change appearance</button><span data-theme-status aria-live="polite"></span></body>
                </html>`;

            return route.fulfill({ status: 200, contentType: 'text/html', body: html });
        }

        return route.fulfill({ status: 204, body: '' });
    });

    await page.goto('http://deployer.signal-theme.test/theme-preference-demo', { waitUntil: 'load' });
    await expect(page.locator('html')).toHaveAttribute('data-appearance', 'dark');
    await expect(page.locator('html')).toHaveClass(/dark/);

    const saveRequest = page.waitForRequest((request) => request.url().endsWith('/__platform/preferences/theme') && request.method() === 'PUT');
    await page.getByRole('button', { name: 'Use light theme' }).click();
    const saved = await saveRequest;
    expect(saved.postDataJSON()).toEqual({ appearance: 'light' });
    expect(saved.headers()['x-csrf-token']).toBe('fixture-csrf-token');
    await expect(page.locator('[data-theme-status]')).toHaveText('Appearance preference saved to your account.');

    await page.goto('http://monitor.signal-theme.test/theme-preference-demo', { waitUntil: 'load' });
    await expect(page.locator('html')).toHaveAttribute('data-appearance', 'light');
    await expect(page.locator('html')).toHaveClass(/light/);
});
