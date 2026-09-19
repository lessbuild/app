const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const fixtures = fs.mkdtempSync(path.join(os.tmpdir(), 'buildpusher-asset-layout-'));
const screens = ['landing', 'login', 'pricing', 'dashboard', 'projects', 'build', 'backups', 'domains', 'observability', 'organization', 'automation', 'configuration-create', 'configuration-review', 'configuration-receipt'];
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
        if ([...screens, 'provider-create', 'feedback'].includes(pathname.slice(1))) {
            const screen = pathname.slice(1);
            const dialog = new URL(route.request().url()).searchParams.get('dialog');
            const fixtureName = screen === 'domains' && dialog === 'add-domain'
                ? 'domains-dialog'
                : screen === 'organization' && dialog === 'invite-member'
                    ? 'organization-dialog'
                    : screen === 'feedback' && dialog === 'compose-feedback'
                        ? 'feedback-dialog'
                        : screen;
            let html = fs.readFileSync(path.join(fixtures, `${fixtureName}.html`), 'utf8');
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
                if (!['landing', 'pricing'].includes(screen)) expect(await page.evaluate(() => typeof window.Livewire)).toBe('object');
                await expect(page.locator('body')).toHaveCSS('background-color', colorScheme === 'dark' ? 'rgb(31, 41, 55)' : 'rgb(255, 255, 255)');
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), screen).toBe(true);
                const primaryText = page.locator('.text-primary').first();
                await expect(primaryText).toHaveCSS('color', colorScheme === 'dark' ? 'rgb(243, 244, 246)' : 'rgb(55, 65, 81)');
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
                    if (width <= 390) {
                        const dashboardStats = page.locator('[data-dashboard-stats]');
                        await expect(dashboardStats).toBeVisible();
                        expect((await dashboardStats.boundingBox()).height).toBeLessThan(170);
                    }
                    const setupTabs = page.locator('[role="tablist"][aria-label="Workspace setup steps"]');
                    const setupSteps = page.locator('[data-dashboard-setup-step]');
                    if (width < 1024) {
                        await expect(setupTabs).toBeVisible();
                        await expect(setupSteps.filter({ hasText: 'Connect a provider' })).toBeVisible();
                        if (width <= 390) {
                            expect((await setupSteps.filter({ hasText: 'Connect a provider' }).boundingBox()).height).toBeLessThan(220);
                            const serverTab = page.locator('#setup-tab-server');
                            await serverTab.click();
                            await expect(page.locator('#setup-panel-server')).toBeVisible();
                            await expect(page.locator('#setup-panel-provider')).toBeHidden();
                            await expect(serverTab).toHaveAttribute('aria-selected', 'true');
                            await serverTab.press('ArrowLeft');
                            await expect(page.locator('#setup-tab-provider')).toBeFocused();
                        }
                    } else {
                        await expect(setupTabs).toBeHidden();
                        await expect(setupSteps).toHaveCount(5);
                    }
                    expect(await page.evaluate(() => {
                        const ids = ['dashboard-attention-title', 'setup-progress-title', 'operations-overview-title'];
                        const elements = ids.map((id) => document.getElementById(id));

                        return Boolean(elements.every(Boolean)
                            && (elements[0].compareDocumentPosition(elements[1]) & Node.DOCUMENT_POSITION_FOLLOWING)
                            && (elements[1].compareDocumentPosition(elements[2]) & Node.DOCUMENT_POSITION_FOLLOWING));
                    }), 'dashboard should present attention, setup, then secondary metrics').toBe(true);
                    const overview = page.locator('#dashboard-operational-overview');
                    const overviewContent = overview.locator('.ui-responsive-details__content');
                    if (width >= 1024) {
                        await expect(overviewContent).toBeVisible();
                    } else {
                        await expect(overviewContent).toBeHidden();
                        await overview.locator('summary').click();
                        await expect(overviewContent).toBeVisible();
                    }
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
                    const quickAction = page.locator('[data-mobile-quick-action="create"]');
                    await expect(quickAction).toHaveText('New app');
                    expect(new URL(await quickAction.getAttribute('href')).pathname).toBe('/projects/create');
                    await expect(quickAction).toHaveCSS('min-height', '44px');
                }
                if (screen === 'build') {
                    const evidence = page.locator('#deployment-evidence');
                    const content = evidence.locator('.ui-responsive-details__content');
                    await expect(evidence).toBeVisible();
                    await expect(page.locator('#deployment-timeline-title')).toBeVisible();
                    if (width >= 1024) {
                        await expect(content).toBeVisible();
                    } else {
                        await expect(content).toBeHidden();
                        await evidence.locator('summary').click();
                        await expect(content).toBeVisible();
                    }
                }
                if (screen === 'backups') {
                    await expect(page.locator('[data-backup-readiness]')).toBeVisible();
                    await expect(page.locator('#backup-destinations')).toBeVisible();
                    await expect(page.locator('#backup-schedules')).toBeVisible();
                    const evidence = page.locator('#backup-recovery-evidence');
                    const content = evidence.locator('.ui-responsive-details__content');
                    if (width >= 1024) {
                        await expect(content).toBeVisible();
                    } else {
                        await expect(content).toBeHidden();
                        await evidence.locator('summary').click();
                        await expect(content).toBeVisible();
                    }
                }
                if (screen === 'domains') {
                    const addDomain = page.getByRole('link', { name: 'Add domain', exact: true });
                    const addDialog = page.getByRole('dialog', { name: 'Add domain', exact: true });

                    await addDomain.click();
                    await expect(addDialog).toBeVisible();
                    await expect(page.locator('#domain-add-dialog [data-modal-close]')).toBeFocused();
                    expect(new URL(page.url()).searchParams.get('dialog')).toBe('add-domain');

                    await page.keyboard.press('Escape');
                    await expect(addDialog).toBeHidden();
                    await expect(addDomain).toBeFocused();
                    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

                    await page.goto(new URL('/domains?dialog=add-domain', 'http://buildpusher.test').toString(), { waitUntil: 'networkidle' });
                    await expect(addDialog).toBeVisible();
                    await page.locator('#domain-add-dialog [data-modal-close]').click();
                    await expect(addDialog).toBeHidden();
                }
                if (screen === 'observability') {
                    await expect(page.locator('#observability-overview')).toBeVisible();
                    await expect(page.locator('#operational-incidents')).toBeVisible();
                    await expect(page.locator('#server-telemetry')).toBeVisible();
                    const signals = page.locator('#correlated-signals');
                    const content = signals.locator('.ui-responsive-details__content');
                    if (width >= 1024) {
                        await expect(content).toBeVisible();
                    } else {
                        await expect(content).toBeHidden();
                        await signals.locator('summary').click();
                        await expect(content).toBeVisible();
                    }
                }
                if (screen === 'projects') {
                    const brand = page.locator('[data-auth-brand]');
                    await expect(brand).toHaveCSS('color', 'rgb(243, 244, 246)');

                    const card = page.locator('[data-project-card]').first();
                    const badge = card.locator('[data-project-environment-count]');
                    await expect(card).toBeVisible();
                    await expect(badge).toBeVisible();
                    expect(await badge.evaluate((element) => element.scrollWidth <= element.clientWidth), 'application count badge must not clip its text').toBe(true);
                    expect(await card.evaluate((element) => {
                        const cardRect = element.getBoundingClientRect();
                        const badgeRect = element.querySelector('[data-project-environment-count]').getBoundingClientRect();
                        return badgeRect.left >= cardRect.left && badgeRect.right <= cardRect.right;
                    }), 'application count badge must remain inside its card').toBe(true);
                }
                if (screen === 'landing') {
                    await expect(page.locator('[data-illustrative-preview]')).toContainText('Example data · not live telemetry');
                    await expect(page.locator('[data-illustrative-preview]')).toContainText('Illustrative workspace');
                }
                if (screen === 'pricing') {
                    const plans = page.locator('[data-pricing-plan]');
                    await expect(plans).toHaveCount(6);
                    const disclosures = page.locator('[data-pricing-plan] [data-responsive-details]');
                    await expect(disclosures).toHaveCount(6);
                    for (let index = 0; index < await disclosures.count(); index += 1) {
                        const disclosure = disclosures.nth(index);
                        const content = disclosure.locator('.ui-responsive-details__content');
                        if (width >= 1024) {
                            await expect(content).toBeVisible();
                        } else {
                            await expect(content).toBeHidden();
                            await disclosure.locator('summary').click();
                            await expect(content).toBeVisible();
                        }
                    }
                }
                if (screen === 'organization' || screen === 'automation') {
                    const disclosureIds = screen === 'organization'
                        ? ['organization-security-policy', 'organization-notification-preferences', 'organization-invite', 'organization-workspaces', 'organization-delete']
                        : ['automation-tokens', 'automation-quick-start'];

                    for (const id of disclosureIds) {
                        const disclosure = page.locator(`#${id}`);
                        const content = disclosure.locator('.ui-responsive-details__content');
                        await expect(disclosure).toBeVisible();
                        if (width >= 1024) {
                            await expect(content).toBeVisible();
                        } else {
                            await expect(content).toBeHidden();
                            await disclosure.locator('summary').click();
                            await expect(content).toBeVisible();
                        }
                    }
                    if (screen === 'automation') {
                        await expect(page.locator('#automation-overview')).toBeVisible();
                        await expect(page.locator('#automation-workflows')).toBeVisible();
                    }
                }
                if (width === 390) await page.screenshot({ path: test.info().outputPath(`${screen}.png`), fullPage: true });
            }
            expect(errors).toEqual([]);
        });
    }
}

// Native controls must preserve provider submission if the JavaScript runtime fails.
test('provider creation submits the selected provider without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    try {
        await serveFixtures(page);
        await page.route('**/providers', route => route.fulfill({ contentType: 'text/html', body: 'Submitted fixture' }));
        await page.goto('http://buildpusher.test/provider-create');
        await page.getByRole('radio', { name: 'DigitalOcean', exact: true }).check();
        await page.locator('#name').fill('Disposable connection');
        await page.locator('#description').fill('Provider form regression');
        await page.locator('#token').fill('fixture-private-token');
        const tokenBounds = await page.locator('#token').boundingBox();
        expect(tokenBounds.y).toBeLessThan(700);
        const request = page.waitForRequest(request => request.method() === 'POST');
        await page.getByRole('button', { name: 'Create Provider', exact: true }).click();
        const submitted = new URLSearchParams((await request).postData());
        expect(submitted.get('provider')).toBe('digitalocean');
        expect(submitted.get('name')).toBe('Disposable connection');
        expect(submitted.get('connection_monitoring_enabled')).toBe('0');
    } finally {
        await context.close();
    }
});

test('provider creation keeps credentials primary and monitoring collapsible on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/provider-create', { waitUntil: 'networkidle' });

    const tokenBounds = await page.locator('#token').boundingBox();
    expect(tokenBounds.y).toBeLessThan(700);

    const monitoring = page.locator('#provider-monitoring-settings');
    const content = monitoring.locator('.ui-responsive-details__content');
    await expect(content).toBeHidden();
    await monitoring.locator('summary').click();
    await expect(content).toBeVisible();
});

test('compact invitation and feedback workflows use accessible URL-backed dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);

    await page.goto('http://buildpusher.test/organization', { waitUntil: 'networkidle' });
    const inviteTrigger = page.getByRole('link', { name: 'Invite member', exact: true });
    const inviteDialog = page.getByRole('dialog', { name: 'Invite member', exact: true });
    await inviteTrigger.click();
    await expect(inviteDialog).toBeVisible();
    await expect(page.locator('#organization-invite [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('invite-member');
    await page.keyboard.press('Escape');
    await expect(inviteDialog).toBeHidden();
    await expect(inviteTrigger).toBeFocused();
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

    await page.goto('http://buildpusher.test/feedback', { waitUntil: 'networkidle' });
    const feedbackTrigger = page.getByRole('link', { name: 'Send feedback', exact: true }).first();
    const feedbackDialog = page.getByRole('dialog', { name: 'Send private feedback', exact: true });
    await feedbackTrigger.click();
    await expect(feedbackDialog).toBeVisible();
    await expect(page.locator('#feedback-compose [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('compose-feedback');
    await page.locator('#feedback-compose [data-modal-close]').click();
    await expect(feedbackDialog).toBeHidden();
    await expect(feedbackTrigger).toBeFocused();
});
