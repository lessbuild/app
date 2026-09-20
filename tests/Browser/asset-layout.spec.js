const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const fixtures = fs.mkdtempSync(path.join(os.tmpdir(), 'buildpusher-asset-layout-'));
const screens = ['landing', 'login', 'pricing', 'dashboard', 'projects', 'websites', 'servers', 'providers', 'repositories', 'recipes', 'project-detail', 'build', 'backups', 'domains', 'observability', 'notifications', 'organization', 'automation', 'gallery', 'gallery-review', 'configuration-create', 'configuration-review', 'configuration-receipt'];
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
        const galleryPage = /^\/gallery\/\d+$/.test(pathname);
        const galleryScriptPage = /^\/gallery\/\d+\/script$/.test(pathname);
        const providerPage = /^\/providers\/\d+$/.test(pathname);
        const repositoryPage = /^\/repositories\/\d+$/.test(pathname);
        const serverPage = /^\/servers\/\d+$/.test(pathname);
        const websitePage = /^\/websites\/\d+$/.test(pathname);
        const projectPage = /^\/projects\/\d+$/.test(pathname);
        const configurationDialogPage = /^\/projects\/\d+\/configuration\/dialog$/.test(pathname);
        if (route.request().method() !== 'GET') return route.fulfill({ status: 204, body: '' });
        if (galleryScriptPage) {
            return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(fixtures, 'gallery-script.html')) });
        }
        if ([...screens, 'provider-create', 'feedback'].includes(pathname.slice(1)) || galleryPage || providerPage || repositoryPage || serverPage || websitePage || projectPage || configurationDialogPage) {
            const screen = galleryPage
                ? 'gallery-detail'
                : providerPage
                    ? 'provider-show'
                        : repositoryPage
                            ? 'repository-show'
                                : serverPage
                                    ? 'server-show'
                            : websitePage
                            ? 'website-show'
                        : configurationDialogPage
                            ? 'configuration-dialog'
                        : projectPage
                            ? 'project-detail'
                        : pathname.slice(1);
            const dialog = new URL(route.request().url()).searchParams.get('dialog');
            const fixtureName = screen === 'domains' && dialog === 'add-domain'
                ? 'domains-dialog'
                : screen === 'organization' && dialog === 'invite-member'
                    ? 'organization-dialog'
                    : screen === 'feedback' && dialog === 'compose-feedback'
                        ? 'feedback-dialog'
                        : screen === 'automation' && dialog === 'create-token'
                            ? 'automation-dialog'
                            : screen === 'backups' && dialog === 'add-schedule'
                                ? 'backups-dialog'
                            : screen === 'backups' && dialog === 'add-destination'
                                ? 'backups-destination-dialog'
                            : screen === 'backups' && dialog?.startsWith('edit-destination-')
                                ? 'backups-destination-edit-dialog'
                            : screen === 'build' && dialog === 'operator-note'
                                ? 'build-note-dialog'
                            : screen === 'gallery' && dialog === 'publish-recipe'
                                ? 'gallery-index-publish-dialog'
                            : screen === 'gallery' && dialog?.startsWith('inspect-script-')
                                ? 'gallery-index-inspect-dialog'
                            : screen === 'gallery'
                                ? 'gallery-index'
                            : screen === 'gallery-detail' && dialog === 'report'
                                ? 'gallery-dialog'
                            : screen === 'gallery-detail'
                                ? 'gallery'
                                : screen === 'observability' && dialog === 'create-metric-rule'
                                    ? 'observability-metric-rule-dialog'
                                : screen === 'observability' && dialog === 'create-alert-destination'
                                    ? 'observability-destination-dialog'
                                : screen === 'observability' && dialog === 'create-status-page'
                                    ? 'observability-status-page-dialog'
                                : screen === 'observability' && dialog === 'create-status-incident'
                                    ? 'observability-status-incident-dialog'
                                : screen === 'observability' && dialog?.startsWith('edit-status-incident-')
                                    ? 'observability-status-incident-edit-dialog'
                                : screen === 'notifications' && dialog === 'save-filter'
                                    ? 'notifications-dialog'
                                    : screen === 'projects' && dialog === 'create-application'
                                        ? 'projects-dialog'
                                        : screen === 'websites' && dialog === 'create-website'
                                            ? 'websites-dialog'
                                                : screen === 'servers' && dialog === 'create-server'
                                                    ? 'servers-dialog'
                            : screen === 'repositories' && dialog === 'create-repository'
                                ? 'repositories-dialog'
                                : screen === 'providers' && dialog === 'create-provider'
                                    ? 'providers-dialog'
                                : screen === 'provider-show' && dialog === 'edit-provider'
                                    ? 'provider-show-edit-dialog'
                                : screen === 'repository-show' && dialog === 'edit-repository'
                                    ? 'repository-show-edit-dialog'
                                : screen === 'repository-show' && dialog === 'repository-webhook-settings'
                                    ? 'repository-show-webhook-dialog'
                                : screen === 'server-show' && dialog === 'edit-display-name'
                                    ? 'server-show-edit-dialog'
                                : screen === 'website-show' && dialog === 'edit-website'
                                    ? 'website-show-edit-dialog'
                                : screen === 'website-show' && dialog === 'website-log-retention'
                                    ? 'website-show-log-retention-dialog'
                                : screen === 'recipes' && dialog === 'create-recipe'
                                    ? 'recipes-dialog'
                                : screen === 'recipes' && dialog?.startsWith('edit-recipe-')
                                    ? 'recipes-edit-dialog'
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

test('observability investigation notes use an accessible dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/observability', { waitUntil: 'networkidle' });

    const incidentTimeline = page.locator('#operational-incidents article details').first();
    if (! await incidentTimeline.evaluate((element) => element.open)) {
        await incidentTimeline.locator('summary').click();
    }
    const noteTrigger = page.getByRole('link', { name: 'Add investigation note', exact: true });
    const noteDialog = page.getByRole('dialog', { name: 'Add investigation note', exact: true });
    await noteTrigger.click();
    await expect(noteDialog).toBeVisible();
    await expect(noteDialog.locator('[data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^incident-note-\d+$/);
    await page.keyboard.press('Escape');
    await expect(noteDialog).toBeHidden();
    await expect(noteTrigger).toBeFocused();
});

test('primary creation workflows use accessible inventory dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);

    for (const workflow of [
        { path: 'projects', trigger: 'New application', title: 'New application', query: 'create-application' },
        { path: 'servers', trigger: 'Add Server', title: 'Add server', query: 'create-server' },
        { path: 'websites', trigger: 'Add Website', title: 'Add website', query: 'create-website' },
        { path: 'providers', trigger: 'Add Provider', title: 'Add provider', query: 'create-provider' },
        { path: 'repositories', trigger: 'Add Repository', title: 'Add repository', query: 'create-repository' },
        { path: 'recipes', trigger: 'Add Recipe', title: 'Add recipe', query: 'create-recipe' },
    ]) {
        await page.goto(`http://buildpusher.test/${workflow.path}`, { waitUntil: 'networkidle' });
        const trigger = page.getByRole('link', { name: workflow.trigger, exact: true }).first();
        const dialog = page.getByRole('dialog', { name: workflow.title, exact: true });

        await trigger.click();
        await expect(dialog).toBeVisible();
        expect(new URL(page.url()).searchParams.get('dialog')).toBe(workflow.query);
        await expect(dialog.locator('[data-modal-close]')).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
        expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

        await page.goto(`http://buildpusher.test/${workflow.path}?dialog=${workflow.query}`, { waitUntil: 'networkidle' });
        await expect(page.getByRole('dialog', { name: workflow.title, exact: true })).toBeVisible();
    }
});

test('dashboard creation actions open page-local dialogs without navigating to an inventory page', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/dashboard', { waitUntil: 'networkidle' });
    const initialDashboardPath = new URL(page.url()).pathname;

    for (const workflow of [
        { selector: '[data-modal-trigger="server-create-dialog"]:visible', title: 'Add server', query: 'create-server' },
        { selector: '[data-modal-trigger="website-create-dialog"]:visible', title: 'Add website', query: 'create-website' },
        { selector: '[data-modal-trigger="application-create-dialog"]:visible', title: 'New application', query: 'create-application' },
        { selector: '[data-modal-trigger="provider-create-dialog"]:visible', title: 'Add provider', query: 'create-provider' },
    ]) {
        const trigger = page.locator(workflow.selector).first();
        const dialog = page.getByRole('dialog', { name: workflow.title, exact: true });
        const triggerPath = new URL(await trigger.getAttribute('href'), page.url()).pathname;

        await trigger.click();
        await expect(dialog).toBeVisible();
        expect(new URL(page.url()).pathname).toBe(triggerPath);
        expect(new URL(page.url()).searchParams.get('dialog')).toBe(workflow.query);

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        expect(new URL(page.url()).pathname).toBe(initialDashboardPath);
        expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);
    }
});

test('mobile New app stays on the current page while opening the application dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/observability', { waitUntil: 'networkidle' });

    const initialUrl = new URL(page.url());
    const trigger = page.locator('[data-mobile-quick-action="create"]');
    const dialog = page.getByRole('dialog', { name: 'New application', exact: true });
    const triggerUrl = new URL(await trigger.getAttribute('href'), page.url());

    expect(triggerUrl.pathname).toBe(initialUrl.pathname);
    expect(triggerUrl.searchParams.get('dialog')).toBe('create-application');

    await trigger.click();
    await expect(dialog).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialUrl.pathname);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('create-application');
    await expect(dialog.locator('[data-modal-close]')).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    expect(new URL(page.url()).pathname).toBe(initialUrl.pathname);
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

    await page.keyboard.press('Control+k');
    await expect(page.getByRole('dialog', { name: 'Command palette' })).toBeVisible();
    await page.locator('#command-palette-result-1').click();
    await expect(page.getByRole('dialog', { name: 'Command palette' })).toBeHidden();
    await expect(dialog).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialUrl.pathname);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('create-application');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
});

test('provider, repository, and recipe edits open server-rendered dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);

    for (const workflow of [
        { path: 'providers/1', trigger: 'Edit Provider', title: 'Edit provider', query: 'edit-provider' },
        { path: 'repositories/1', trigger: 'Edit', title: 'Edit repository', query: 'edit-repository' },
        { path: 'repositories/1', trigger: 'Enable webhook', title: 'Webhook settings', query: 'repository-webhook-settings' },
        { path: 'servers/1', trigger: 'Edit Display Name', title: 'Edit server display name', query: 'edit-display-name' },
        { path: 'websites/1', trigger: 'Edit Website', title: 'Edit website', query: 'edit-website' },
    ]) {
        await page.goto(`http://buildpusher.test/${workflow.path}`, { waitUntil: 'networkidle' });
        const trigger = page.getByRole('link', { name: workflow.trigger, exact: true }).first();
        await trigger.click();
        const dialog = page.getByRole('dialog', { name: workflow.title, exact: true });
        await expect(dialog).toBeVisible();
        expect(new URL(page.url()).searchParams.get('dialog')).toBe(workflow.query);
        await expect(dialog.locator('[data-modal-close]')).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    }

    await page.goto('http://buildpusher.test/recipes', { waitUntil: 'networkidle' });
    const recipeTrigger = page.getByRole('link', { name: 'Edit', exact: true }).first();
    const recipeUrl = new URL(await recipeTrigger.getAttribute('href'), 'http://buildpusher.test');
    await recipeTrigger.click();
    const recipeDialog = page.getByRole('dialog', { name: 'Edit recipe', exact: true });
    await expect(recipeDialog).toBeVisible();
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^edit-recipe-\d+$/);
    await expect(recipeDialog.locator('[data-modal-close]')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(recipeDialog).toBeHidden();
    await expect(recipeTrigger).toBeFocused();

    await page.goto(`http://buildpusher.test${recipeUrl.pathname}${recipeUrl.search}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Edit recipe', exact: true })).toBeVisible();
});

test('website log retention opens in a contextual dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/websites/1', { waitUntil: 'networkidle' });

    await page.locator('#website-runtime-logs summary').click();
    const trigger = page.getByRole('link', { name: 'Configure retention', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Log retention settings', exact: true });
    await trigger.click();
    await expect(dialog).toBeVisible();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('website-log-retention');
    await expect(dialog.locator('[data-modal-close]')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/websites/1?dialog=website-log-retention', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Log retention settings', exact: true })).toBeVisible();
});

test('gallery publishing and script inspection use accessible dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/gallery', { waitUntil: 'networkidle' });

    const publishTrigger = page.getByRole('link', { name: 'Publish a Recipe', exact: true });
    const publishDialog = page.getByRole('dialog', { name: 'Publish a recipe', exact: true });
    await publishTrigger.click();
    await expect(publishDialog).toBeVisible();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('publish-recipe');
    await expect(publishDialog.locator('[data-modal-close]')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(publishDialog).toBeHidden();
    await expect(publishTrigger).toBeFocused();

    const inspectTrigger = page.getByRole('link', { name: 'Inspect script', exact: true }).first();
    const inspectUrl = new URL(await inspectTrigger.getAttribute('href'), 'http://buildpusher.test');
    const inspectDialog = page.getByRole('dialog', { name: 'Inspect Gallery fixture recipe', exact: true });
    await inspectTrigger.click();
    await expect(inspectDialog).toBeVisible();
    await expect(inspectDialog).toContainText('echo gallery-fixture');
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^inspect-script-\d+$/);
    await expect(inspectDialog.locator('[data-modal-close]')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(inspectDialog).toBeHidden();
    await expect(inspectTrigger).toBeFocused();

    await page.goto(`http://buildpusher.test${inspectUrl.pathname}${inspectUrl.search}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Inspect Gallery fixture recipe', exact: true })).toBeVisible();
    await expect(page.getByRole('dialog', { name: 'Inspect Gallery fixture recipe', exact: true })).toContainText('echo gallery-fixture');
});

test('mobile forms keep focused fields reachable and lazy dialog content exposes busy state', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/provider-create', { waitUntil: 'networkidle' });

    const token = page.locator('#token');
    await expect(token).toHaveCSS('font-size', '16px');
    await expect(token).toHaveCSS('scroll-margin-block-start', '64px');
    await expect(page.locator('#provider-errors')).toHaveCount(0);

    await page.goto('http://buildpusher.test/gallery', { waitUntil: 'networkidle' });
    await page.route('**/gallery/1/script', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 500));
        await route.fulfill({
            contentType: 'text/html',
            body: fs.readFileSync(path.join(fixtures, 'gallery-script.html')),
        });
    });

    const inspectTrigger = page.getByRole('link', { name: 'Inspect script', exact: true }).first();
    await inspectTrigger.click();
    const inspectDialog = page.getByRole('dialog', { name: 'Inspect Gallery fixture recipe', exact: true });
    const content = inspectDialog.locator('[data-modal-content]');
    await expect(content).toHaveAttribute('aria-busy', 'true');
    await expect(content).toContainText('echo gallery-fixture');
    await expect(content).not.toHaveAttribute('aria-busy', 'true');
});

test('lazy dialog failures provide a retry and a contextual full-page fallback', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/gallery', { waitUntil: 'networkidle' });

    await page.route('**/gallery/1/script', async (route) => {
        await route.fulfill({ status: 503, body: 'temporarily unavailable' });
    });

    const trigger = page.getByRole('link', { name: 'Inspect script', exact: true }).first();
    const dialog = page.getByRole('dialog', { name: 'Inspect Gallery fixture recipe', exact: true });
    await trigger.click();
    await expect(dialog.getByRole('alert')).toContainText('could not be loaded');
    await expect(dialog.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
    await expect(dialog.getByRole('link', { name: 'Open full page', exact: true })).toHaveAttribute('href', /dialog=inspect-script-1/);

    await page.unroute('**/gallery/1/script');
    await dialog.getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(dialog).toContainText('echo gallery-fixture');
    await expect(dialog.locator('[data-modal-content]')).not.toHaveAttribute('aria-busy', 'true');
});

test('open dialogs lock the page and scroll their own body', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/providers', { waitUntil: 'networkidle' });

    const dialog = page.locator('#provider-create-dialog');
    const body = dialog.locator('[data-modal-body]');

    await page.getByRole('link', { name: 'Add Provider', exact: true }).first().click();
    await expect(dialog).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-modal-open', '');
    await expect(page.locator('body')).toHaveAttribute('data-modal-open', '');

    const pageScrollTop = await page.evaluate(() => document.scrollingElement.scrollTop);
    await page.mouse.wheel(0, 1200);
    await expect.poll(() => page.evaluate(() => document.scrollingElement.scrollTop)).toBe(pageScrollTop);

    const bodyMetrics = await body.evaluate((element) => {
        element.scrollTop = element.scrollHeight;

        return {
            scrollTop: element.scrollTop,
            scrollHeight: element.scrollHeight,
            clientHeight: element.clientHeight,
        };
    });

    expect(bodyMetrics.scrollHeight).toBeGreaterThan(bodyMetrics.clientHeight);
    expect(bodyMetrics.scrollTop).toBeGreaterThan(0);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(page.locator('html')).not.toHaveAttribute('data-modal-open', '');
    await expect(page.locator('body')).not.toHaveAttribute('data-modal-open', '');
});

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
                    const quickActionUrl = new URL(await quickAction.getAttribute('href'));
                    // The dashboard fixture is requested through its historical
                    // /dashboard screen name, while the named application route
                    // is /home. Compare with the rendered canonical dashboard
                    // link so this fixture still tests same-page modal state.
                    const expectedCurrentPath = screen === 'dashboard'
                        ? new URL(await page.locator('[data-auth-brand]').getAttribute('href'), page.url()).pathname
                        : new URL(page.url()).pathname;
                    expect(quickActionUrl.pathname).toBe(expectedCurrentPath);
                    expect(quickActionUrl.searchParams.get('dialog')).toBe('create-application');
                    await expect(quickAction).toHaveCSS('min-height', '44px');
                }
                if (width <= 390 && ['projects', 'servers', 'providers', 'repositories', 'websites', 'recipes'].includes(screen)) {
                    const pageHeader = page.locator('[data-ui-page-header]');
                    const pageHeaderActions = page.locator('[data-ui-page-header-actions]');
                    await expect(pageHeader).toBeVisible();
                    await expect(pageHeaderActions).toHaveCSS('display', 'grid');
                    expect(await pageHeaderActions.evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length)).toBe(2);
                }
                if (screen === 'projects' && width <= 390) {
                    const projectCard = page.locator('[data-project-card]').first();
                    await expect(projectCard).toBeVisible();
                    await expect(projectCard).toHaveCSS('min-height', '0px');
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
                    const noteTrigger = page.getByRole('link', { name: 'Add operator note', exact: true });
                    const noteDialog = page.getByRole('dialog', { name: 'Add operator note', exact: true });
                    await noteTrigger.click();
                    await expect(noteDialog).toBeVisible();
                    await expect(noteDialog.locator('[data-modal-close]')).toBeFocused();
                    expect(new URL(page.url()).searchParams.get('dialog')).toBe('operator-note');
                    await page.keyboard.press('Escape');
                    await expect(noteDialog).toBeHidden();
                    await expect(noteTrigger).toBeFocused();
                    await page.goto('http://buildpusher.test/build?dialog=operator-note', { waitUntil: 'networkidle' });
                    await expect(page.getByRole('dialog', { name: 'Add operator note', exact: true })).toBeVisible();
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
                    const scheduleTrigger = page.getByRole('link', { name: 'Add schedule', exact: true });
                    const scheduleDialog = page.getByRole('dialog', { name: 'Add schedule', exact: true });
                    await scheduleTrigger.click();
                    await expect(scheduleDialog).toBeVisible();
                    await expect(page.locator('#backup-schedule-dialog [data-modal-close]')).toBeFocused();
                    expect(new URL(page.url()).searchParams.get('dialog')).toBe('add-schedule');
                    await page.keyboard.press('Escape');
                    await expect(scheduleDialog).toBeHidden();
                    await expect(scheduleTrigger).toBeFocused();
                    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);
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
                    const incidentTimeline = page.locator('#operational-incidents article details').first();
                    if (! await incidentTimeline.evaluate((element) => element.open)) {
                        await incidentTimeline.locator('summary').click();
                    }
                    const noteTrigger = page.getByRole('link', { name: 'Add investigation note', exact: true });
                    const noteDialog = page.getByRole('dialog', { name: 'Add investigation note', exact: true });
                    await noteTrigger.click();
                    await expect(noteDialog).toBeVisible();
                    await expect(noteDialog.locator('[data-modal-close]')).toBeFocused();
                    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^incident-note-\d+$/);
                    await page.keyboard.press('Escape');
                    await expect(noteDialog).toBeHidden();
                    await expect(noteTrigger).toBeFocused();
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
                if (screen === 'notifications') {
                    await page.locator('#notification-saved-filters summary').click();
                    const savedFilterTrigger = page.getByRole('link', { name: 'Save current', exact: true });
                    const savedFilterDialog = page.getByRole('dialog', { name: 'Save notification filter', exact: true });
                    await savedFilterTrigger.click();
                    await expect(savedFilterDialog).toBeVisible();
                    await expect(page.locator('#notification-save-filter-dialog [data-modal-close]')).toBeFocused();
                    expect(new URL(page.url()).searchParams.get('dialog')).toBe('save-filter');
                    await page.keyboard.press('Escape');
                    await expect(savedFilterDialog).toBeHidden();
                    await expect(savedFilterTrigger).toBeFocused();
                    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

                    await page.goto('http://buildpusher.test/notifications?dialog=save-filter', { waitUntil: 'networkidle' });
                    await expect(savedFilterDialog).toBeVisible();
                    await page.locator('#notification-save-filter-dialog [data-modal-close]').click();
                    await expect(savedFilterDialog).toBeHidden();
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
                        ? ['organization-security-policy', 'organization-notification-preferences', 'organization-workspaces', 'organization-delete']
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
                    if (screen === 'organization') {
                        const inviteTrigger = page.getByRole('link', { name: 'Invitations', exact: true });
                        const inviteDialog = page.getByRole('dialog', { name: 'Invite member', exact: true });
                        await inviteTrigger.click();
                        await expect(inviteDialog).toBeVisible();
                        await expect(page.locator('#organization-invite [data-modal-close]')).toBeFocused();
                        expect(new URL(page.url()).searchParams.get('dialog')).toBe('invite-member');
                        await page.keyboard.press('Escape');
                        await expect(inviteDialog).toBeHidden();
                        await expect(inviteTrigger).toBeFocused();
                        expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

                        await page.goto('http://buildpusher.test/organization?dialog=invite-member', { waitUntil: 'networkidle' });
                        await expect(inviteDialog).toBeVisible();
                        await page.locator('#organization-invite [data-modal-close]').click();
                        await expect(inviteDialog).toBeHidden();
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
        await page.getByRole('button', { name: 'Add Provider', exact: true }).click();
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

    const providerDialog = page.locator('#provider-create-dialog');
    const providerPanel = providerDialog.locator('[data-modal-panel]');
    await expect(providerDialog).toHaveAttribute('data-modal-sheet', '');
    await expect(providerPanel).toHaveCSS('border-top-left-radius', '16px');
    await expect(providerDialog.locator('[data-modal-body]')).toHaveCSS('overscroll-behavior', 'contain');

    const tokenBounds = await page.locator('#token').boundingBox();
    expect(tokenBounds.y).toBeLessThan(700);

    const monitoring = page.locator('#provider-monitoring-settings');
    const content = monitoring.locator('.ui-responsive-details__content');
    await expect(content).toBeHidden();
    await monitoring.locator('summary').click();
    await expect(content).toBeVisible();
});

test('mobile filters use native bottom-sheet dialogs without changing filter URLs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);

    for (const screen of ['repositories', 'providers']) {
        await page.goto(`http://buildpusher.test/${screen}`, { waitUntil: 'networkidle' });

        const filter = page.locator(`#${screen}-filters`);
        const trigger = page.locator(`[data-filter-dialog-trigger][aria-controls="${screen}-filters"]`);
        const initialPath = new URL(page.url()).pathname;

        await expect(filter).toHaveAttribute('data-filter-dialog', '');
        await expect(filter).not.toHaveAttribute('open', '');
        await trigger.click();
        await expect(filter).toHaveAttribute('open', '');
        await expect(filter.locator('.ui-filter-dialog__panel')).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('data-modal-open', '');
        await expect(page.locator('body')).toHaveAttribute('data-modal-open', '');
        await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');
        await expect(filter.locator('.ui-filter-dialog__body')).toHaveCSS('overscroll-behavior', 'contain');
        expect(new URL(page.url()).pathname).toBe(initialPath);

        await page.keyboard.press('Escape');
        await expect(filter).not.toHaveAttribute('open', '');
        await expect(page.locator('html')).not.toHaveAttribute('data-modal-open', '');
        await expect(trigger).toBeFocused();
    }
});

test('mobile connection feedback stays above quick actions and restores cleanly', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/repositories', { waitUntil: 'networkidle' });

    const status = page.locator('[data-network-status]');

    await expect(status).toBeHidden();

    await page.evaluate(() => window.dispatchEvent(new Event('offline')));
    await expect(status).toBeVisible();
    await expect(status).toContainText('You appear to be offline');
    expect(await status.evaluate((element) => getComputedStyle(element).position)).toBe('fixed');

    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect(status).toContainText('Connection restored');
    await expect(status).toBeHidden({ timeout: 6000 });
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

test('backup schedule workflow uses an accessible URL-backed dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/backups', { waitUntil: 'networkidle' });

    const scheduleTrigger = page.getByRole('link', { name: 'Add schedule', exact: true });
    const scheduleDialog = page.getByRole('dialog', { name: 'Add schedule', exact: true });
    await scheduleTrigger.click();
    await expect(scheduleDialog).toBeVisible();
    await expect(page.locator('#backup-schedule-dialog [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('add-schedule');

    await page.keyboard.press('Escape');
    await expect(scheduleDialog).toBeHidden();
    await expect(scheduleTrigger).toBeFocused();
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

    await page.goto('http://buildpusher.test/backups?dialog=add-schedule', { waitUntil: 'networkidle' });
    await expect(scheduleDialog).toBeVisible();
    await page.locator('#backup-schedule-dialog [data-modal-close]').click();
    await expect(scheduleDialog).toBeHidden();

    await page.goto('http://buildpusher.test/backups', { waitUntil: 'networkidle' });
    const destinationTrigger = page.getByRole('link', { name: 'Add destination', exact: true });
    const destinationDialog = page.getByRole('dialog', { name: 'Add backup destination', exact: true });
    await destinationTrigger.click();
    await expect(destinationDialog).toBeVisible();
    await expect(page.locator('#backup-destination-create-dialog [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('add-destination');

    await page.keyboard.press('Escape');
    await expect(destinationDialog).toBeHidden();
    await expect(destinationTrigger).toBeFocused();

    const editDestinationTrigger = page.getByRole('link', { name: 'Edit connection', exact: true });
    const editDestinationUrl = new URL(await editDestinationTrigger.getAttribute('href'), 'http://buildpusher.test');
    const editDestinationDialog = page.getByRole('dialog', { name: 'Edit backup destination', exact: true });
    await editDestinationTrigger.click();
    await expect(editDestinationDialog).toBeVisible();
    await expect(page.locator('#backup-destination-edit-1 [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('edit-destination-1');
    await page.keyboard.press('Escape');
    await expect(editDestinationDialog).toBeHidden();
    await expect(editDestinationTrigger).toBeFocused();

    await page.goto(`http://buildpusher.test${editDestinationUrl.pathname}${editDestinationUrl.search}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Edit backup destination', exact: true })).toBeVisible();
});

test('credential workflows use compact accessible dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/automation', { waitUntil: 'networkidle' });

    const tokenTrigger = page.getByRole('link', { name: 'Create token', exact: true });
    const tokenDialog = page.getByRole('dialog', { name: 'Create personal access token', exact: true });
    await tokenTrigger.click();
    await expect(tokenDialog).toBeVisible();
    await expect(page.locator('#automation-token-dialog [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('create-token');
    await page.keyboard.press('Escape');
    await expect(tokenDialog).toBeHidden();
    await expect(tokenTrigger).toBeFocused();
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

    await page.goto('http://buildpusher.test/automation?dialog=create-token', { waitUntil: 'networkidle' });
    await expect(tokenDialog).toBeVisible();
    await page.locator('#automation-token-dialog [data-modal-close]').click();
    await expect(tokenDialog).toBeHidden();
});

test('automation schedule and task composers use compact accessible dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/automation', { waitUntil: 'networkidle' });
    await page.locator('details[id^="automation-project-"]').first().locator('summary').click();

    const scheduleTrigger = page.getByRole('link', { name: 'Add schedule', exact: true }).first();
    const scheduleDialog = page.getByRole('dialog', { name: 'Add deployment schedule', exact: true }).first();
    await scheduleTrigger.click();
    await expect(scheduleDialog).toBeVisible();
    await expect(scheduleDialog.locator('[data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^deployment-schedule-/);
    await page.keyboard.press('Escape');
    await expect(scheduleDialog).toBeHidden();
    await expect(scheduleTrigger).toBeFocused();

    const taskTrigger = page.getByRole('link', { name: 'Add task', exact: true }).first();
    const taskDialog = page.getByRole('dialog', { name: 'Add scheduled task', exact: true }).first();
    await taskTrigger.click();
    await expect(taskDialog).toBeVisible();
    await expect(taskDialog.locator('[data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^scheduled-task-/);
    await page.locator('[id^="automation-task-dialog-"] [data-modal-close]').click();
    await expect(taskDialog).toBeHidden();
    await expect(taskTrigger).toBeFocused();
});

test('application detail composers use compact accessible dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/project-detail', { waitUntil: 'networkidle' });

    const workflows = [
        ['Add environment', 'Add environment', '#add-environment-dialog'],
        ['Edit settings', 'Environment settings', '[id^="environment-settings-dialog-"]'],
        ['Edit controls', 'Deployment controls', '[id^="environment-deployment-controls-dialog-"]'],
        ['Add variable', 'Add encrypted variable', '[id^="environment-variable-dialog-"]'],
        ['Add process', 'Add worker or scheduler', '[id^="environment-process-dialog-"]'],
        ['Attach resource', 'Attach resource', '[id^="environment-resource-dialog-"]'],
        ['Configure previews', 'Preview environment settings', '#project-preview-settings-dialog'],
    ];

    for (const [triggerName, dialogName, dialogSelector] of workflows) {
        if (triggerName === 'Attach resource') {
            await page.locator('details[id$="-resources"] summary').first().click();
        }
        if (triggerName === 'Configure previews') {
            await page.locator('#preview-environments summary').click();
        }

        const trigger = page.getByRole('link', { name: triggerName, exact: true });
        const dialog = page.getByRole('dialog', { name: dialogName, exact: true });
        await trigger.click();
        await expect(dialog).toBeVisible();
        await expect(page.locator(`${dialogSelector} [data-modal-close]`)).toBeFocused();
        const dialogKey = new URL(page.url()).searchParams.get('dialog');
        const expectedDialog = {
            'Add environment': /^add-environment$/,
            'Edit settings': /^edit-environment-settings-\d+$/,
            'Edit controls': /^edit-deployment-controls-\d+$/,
            'Add variable': /^add-variable-\d+$/,
            'Add process': /^add-process-\d+$/,
            'Attach resource': /^add-resource-\d+$/,
            'Configure previews': /^preview-settings$/,
        }[triggerName];
        expect(dialogKey).toMatch(expectedDialog);
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
        expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);
    }
});

test('configuration as code opens in the application context', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/project-detail', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'Configuration as code', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Configuration as code', exact: true });
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText('Create a review', { exact: true })).toBeVisible();
    await expect(dialog.locator('textarea[name="document"]')).toBeVisible();
    await expect(dialog.locator('[data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('application-configuration');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);
});

test('gallery report composer uses an accessible URL-backed dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/gallery/1', { waitUntil: 'networkidle' });

    const reportTrigger = page.getByRole('link', { name: 'Report issue', exact: true });
    const reportDialog = page.getByRole('dialog', { name: 'Report a recipe issue', exact: true });
    await reportTrigger.click();
    await expect(reportDialog).toBeVisible();
    await expect(page.locator('#gallery-report-dialog [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('report');
    await page.keyboard.press('Escape');
    await expect(reportDialog).toBeHidden();
    await expect(reportTrigger).toBeFocused();
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

    await page.goto('http://buildpusher.test/gallery/1?dialog=report', { waitUntil: 'networkidle' });
    await expect(reportDialog).toBeVisible();
    await page.locator('#gallery-report-dialog [data-modal-close]').click();
    await expect(reportDialog).toBeHidden();
});

test('gallery contributor resolution uses per-report accessible dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/gallery-review', { waitUntil: 'networkidle' });

    const resolveTrigger = page.getByRole('link', { name: 'Mark Resolved', exact: true });
    const resolveDialog = page.getByRole('dialog', { name: 'Resolve community report', exact: true });
    await resolveTrigger.click();
    await expect(resolveDialog).toBeVisible();
    await expect(resolveDialog.locator('[data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^resolve-report-\d+$/);
    await page.keyboard.press('Escape');
    await expect(resolveDialog).toBeHidden();
    await expect(resolveTrigger).toBeFocused();

    const noteTrigger = page.getByRole('link', { name: 'Update Resolution Note', exact: true });
    const noteDialog = page.getByRole('dialog', { name: 'Edit resolution note', exact: true });
    await noteTrigger.click();
    await expect(noteDialog).toBeVisible();
    await expect(noteDialog.locator('[data-modal-close]')).toBeFocused();
    await noteDialog.locator('[data-modal-close]').click();
    await expect(noteDialog).toBeHidden();
    await expect(noteTrigger).toBeFocused();
});

test('metric alert rule composer uses an accessible URL-backed dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/observability', { waitUntil: 'networkidle' });

    const ruleTrigger = page.getByRole('link', { name: 'Create alert rule', exact: true });
    const ruleDialog = page.getByRole('dialog', { name: 'Create an alert rule', exact: true });
    await ruleTrigger.click();
    await expect(ruleDialog).toBeVisible();
    await expect(page.locator('#metric-rule-dialog [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('create-metric-rule');
    await page.keyboard.press('Escape');
    await expect(ruleDialog).toBeHidden();
    await expect(ruleTrigger).toBeFocused();
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

    await page.goto('http://buildpusher.test/observability?dialog=create-metric-rule', { waitUntil: 'networkidle' });
    await expect(ruleDialog).toBeVisible();
    await page.locator('#metric-rule-dialog [data-modal-close]').click();
    await expect(ruleDialog).toBeHidden();
});

test('observability management forms use compact accessible dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/observability', { waitUntil: 'networkidle' });

    for (const section of ['#alert-destinations', '#status-pages', '#status-incident-history']) {
        const details = page.locator(section);
        if (! await details.evaluate((element) => element.open)) {
            await details.locator('summary').click();
        }
    }

    const workflows = [
        ['Add alert destination', 'Add alert destination', '#alert-destination-create-dialog', 'create-alert-destination'],
        ['Create status page', 'Create status page', '#status-page-create-dialog', 'create-status-page'],
        ['Publish a status update', 'Publish a status update', '#status-incident-create-dialog', 'create-status-incident'],
        ['Update or complete review', 'Update or complete review', '[id^="status-incident-edit-dialog-"]', 'edit-status-incident'],
    ];

    for (const [triggerName, dialogName, dialogSelector, dialogKey] of workflows) {
        const trigger = page.getByRole('link', { name: triggerName, exact: true }).first();
        const dialog = page.getByRole('dialog', { name: dialogName, exact: true }).first();
        await trigger.click();
        await expect(dialog).toBeVisible();
        await expect(dialog.locator('[data-modal-close]')).toBeFocused();
        const activeDialogKey = new URL(page.url()).searchParams.get('dialog');
        if (dialogKey === 'edit-status-incident') {
            expect(activeDialogKey).toMatch(/^edit-status-incident-\d+$/);
        } else {
            expect(activeDialogKey).toBe(dialogKey);
        }
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
    }
});

test('notification saved-filter composer uses an accessible URL-backed dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/notifications', { waitUntil: 'networkidle' });

    await page.locator('#notification-saved-filters summary').click();
    const savedFilterTrigger = page.getByRole('link', { name: 'Save current', exact: true });
    const savedFilterDialog = page.getByRole('dialog', { name: 'Save notification filter', exact: true });
    await savedFilterTrigger.click();
    await expect(savedFilterDialog).toBeVisible();
    await expect(page.locator('#notification-save-filter-dialog [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('save-filter');
    await page.keyboard.press('Escape');
    await expect(savedFilterDialog).toBeHidden();
    await expect(savedFilterTrigger).toBeFocused();
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

    await page.goto('http://buildpusher.test/notifications?dialog=save-filter', { waitUntil: 'networkidle' });
    await expect(savedFilterDialog).toBeVisible();
    await page.locator('#notification-save-filter-dialog [data-modal-close]').click();
    await expect(savedFilterDialog).toBeHidden();
});
