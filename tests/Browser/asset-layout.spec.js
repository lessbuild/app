const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const fixtures = fs.mkdtempSync(path.join(os.tmpdir(), 'buildpusher-asset-layout-'));
const screens = ['landing', 'login', 'pricing', 'dashboard', 'projects', 'websites', 'servers', 'providers', 'repositories', 'recipes', 'project-detail', 'builds', 'build', 'backups', 'domains', 'observability', 'notifications', 'organization', 'automation', 'gallery', 'gallery-review', 'account', 'activity', 'commands', 'configuration-create', 'configuration-review', 'configuration-receipt', 'system-health'];
const modalAuditScreens = [...screens, 'providers/1', 'repositories/1', 'servers/1', 'websites/1', 'projects/1', 'gallery/1', 'observability/environments/1/context'];
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
async function serveFixtures(page, { delays = {} } = {}) {
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
    const stylesheet = `/build/${manifest['resources/css/app.css'].file}`;
    const alpine = `/build/${manifest['resources/js/alpine.js'].file}`;
    const signalTheme = `/build/${manifest['resources/js/signal-theme.js'].file}`;
    await page.route('**/*', async (route) => {
        const pathname = new URL(route.request().url()).pathname;
        if (delays[pathname]) {
            await new Promise((resolve) => setTimeout(resolve, delays[pathname]));
        }
        const galleryPage = /^\/gallery\/\d+$/.test(pathname);
        const galleryScriptPage = /^\/gallery\/\d+\/script$/.test(pathname);
        const galleryMyReportsPage = pathname === '/gallery/my-reports';
        const galleryReportStatusPage = /^\/gallery\/reports\/\d+\/status$/.test(pathname);
        const repositoryImpactPreviewPage = pathname === '/repositories/impact-preview';
        const buildComparisonPage = /^\/builds\/\d+\/compare\/\d+$/.test(pathname);
        const buildPage = /^\/builds\/\d+$/.test(pathname);
        const providerPage = /^\/providers\/\d+$/.test(pathname);
        const providerConnectionChecksPage = /^\/providers\/\d+\/connection-checks$/.test(pathname);
        const repositoryPage = /^\/repositories\/\d+$/.test(pathname);
        const serverPage = /^\/servers\/\d+$/.test(pathname);
        const serverCommandsPage = /^\/servers\/\d+\/commands$/.test(pathname);
        const serverCommandOutputPage = /^\/servers\/\d+\/commands\/\d+\/output$/.test(pathname);
        const scheduledTaskRunOutputPage = /^\/automation\/task-runs\/\d+\/output$/.test(pathname);
        const websitePage = /^\/websites\/\d+$/.test(pathname);
        const websiteHealthChecksPage = /^\/websites\/\d+\/health-checks$/.test(pathname);
        const signInHistoryPage = pathname === '/account/sign-ins';
        const environmentContextPage = /^\/observability\/environments\/\d+\/context$/.test(pathname);
        const activityPage = pathname === '/activity';
        const dashboardPage = pathname === '/dashboard' || pathname === '/home';
        const projectPage = /^\/projects\/\d+$/.test(pathname);
        const modalContentFixture = /^\/providers\/\d+\/edit$/.test(pathname)
            ? 'provider-edit-content'
            : /^\/repositories\/\d+\/edit$/.test(pathname)
                ? 'repository-edit-content'
                : /^\/websites\/\d+\/edit$/.test(pathname)
                    ? 'website-edit-content'
                    : /^\/recipes\/\d+\/edit$/.test(pathname)
                        ? 'recipe-edit-content'
                        : /^\/backups\/destinations\/\d+\/edit$/.test(pathname)
                            ? 'backup-destination-edit-content'
                            : null;
        const configurationDialogPage = /^\/projects\/\d+\/configuration\/dialog$/.test(pathname);
        if (route.request().method() !== 'GET') return route.fulfill({ status: 204, body: '' });
        if (modalContentFixture) {
            return route.fulfill({
                contentType: 'text/html',
                body: fs.readFileSync(path.join(fixtures, `${modalContentFixture}.html`)),
            });
        }
        if (galleryScriptPage) {
            return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(fixtures, 'gallery-script.html')) });
        }
        if ([...screens, 'provider-create', 'feedback'].includes(pathname.slice(1)) || dashboardPage || galleryPage || galleryMyReportsPage || galleryReportStatusPage || repositoryImpactPreviewPage || buildComparisonPage || buildPage || providerPage || providerConnectionChecksPage || repositoryPage || serverPage || serverCommandsPage || serverCommandOutputPage || scheduledTaskRunOutputPage || websitePage || websiteHealthChecksPage || signInHistoryPage || environmentContextPage || activityPage || projectPage || configurationDialogPage || pathname === '/builds' && new URL(route.request().url()).searchParams.get('fragment') === 'deployment-history') {
            const screen = dashboardPage
                ? 'dashboard'
                : galleryPage
                ? 'gallery-detail'
                : galleryMyReportsPage
                    ? 'gallery-my-reports'
                : galleryReportStatusPage
                    ? 'gallery-report-status'
                : repositoryImpactPreviewPage
                    ? 'repository-impact-preview'
                : buildComparisonPage
                    ? 'build-comparison'
                : buildPage
                    ? 'build'
                : providerPage
                    ? 'provider-show'
                        : providerConnectionChecksPage
                            ? 'provider-connection-checks'
                        : repositoryPage
                            ? 'repository-show'
                        : serverCommandsPage || serverCommandOutputPage
                            ? 'server-commands'
                                : scheduledTaskRunOutputPage
                                    ? 'automation-task-run-output'
                                : serverPage
                                    ? 'server-show'
                            : websitePage
                            ? 'website-show'
                        : websiteHealthChecksPage
                            ? 'website-health-checks'
                        : signInHistoryPage
                            ? 'sign-in-history'
                        : environmentContextPage
                            ? 'observability-environment-context'
                        : activityPage
                            ? 'activity'
                        : configurationDialogPage
                            ? 'configuration-dialog'
                        : projectPage
                            ? 'project-detail'
                        : pathname.slice(1);
            const dialog = new URL(route.request().url()).searchParams.get('dialog');
            const fragment = new URL(route.request().url()).searchParams.get('fragment');
            const active = new URL(route.request().url()).searchParams.get('active');
            const category = new URL(route.request().url()).searchParams.get('category');
            const fixtureName = screen === 'builds' && fragment === 'deployment-history' && active === '1'
                ? 'dashboard-active-deployments'
                : screen === 'builds' && fragment === 'deployment-history'
                ? 'website-deployment-history'
                : screen === 'server-commands' && fragment === 'server-command-history'
                    ? 'server-command-history'
                : screen === 'server-commands' && fragment === 'server-command-output'
                    ? 'server-command-output-content'
                : screen === 'server-commands' && dialog?.startsWith('server-command-output-')
                    ? 'server-command-output-dialog'
                : screen === 'provider-connection-checks' && !fragment
                    ? 'provider-connection-checks-page'
                : screen === 'automation-task-run-output' && fragment === 'scheduled-task-output'
                    ? 'automation-task-run-output'
                : screen === 'gallery-report-status' && fragment === 'report-status'
                    ? 'gallery-report-status-content'
                : screen === 'repository-impact-preview' && fragment === 'repository-impact-preview'
                    ? 'repositories-impact-preview-content'
                : screen === 'build-comparison' && fragment === 'build-comparison'
                    ? 'build-comparison-content'
                : screen === 'sign-in-history' && fragment === 'sign-in-history'
                    ? 'sign-in-history-content'
                : screen === 'observability-environment-context' && dialog === 'environment-health-checks-dialog'
                    ? 'observability-environment-context-dialog'
                : screen === 'activity' && fragment === 'account-audit'
                    ? 'account-audit-content'
                : screen === 'activity' && fragment === 'workspace-activity'
                    && category === 'deployment'
                    ? 'deployment-activity-content'
                : screen === 'activity' && fragment === 'workspace-activity'
                    ? 'workspace-activity-content'
                : screen === 'system-health' && fragment === 'system-health'
                    ? 'system-health-content'
                : screen === 'commands' && fragment === 'active-command-history'
                    ? 'dashboard-active-commands'
                : screen === 'domains' && dialog === 'add-domain'
                ? 'domains-dialog'
                : screen === 'organization' && dialog?.startsWith('member-role-')
                    ? 'organization-member-role-dialog'
                : screen === 'organization' && dialog === 'organization-notification-preferences-dialog'
                    ? 'organization-notification-preferences-dialog'
                : screen === 'organization' && dialog === 'invite-member'
                    ? 'organization-dialog'
                    : screen === 'feedback' && dialog === 'compose-feedback'
                        ? 'feedback-dialog'
                        : screen === 'automation' && dialog === 'create-token'
                            ? 'automation-dialog'
                            : screen === 'automation' && dialog?.startsWith('scheduled-task-run-')
                                ? 'automation-run-dialog'
                            : screen === 'backups' && dialog === 'add-schedule'
                                ? 'backups-dialog'
                            : screen === 'backups' && dialog === 'add-destination'
                                ? 'backups-destination-dialog'
                            : screen === 'backups' && dialog?.startsWith('edit-destination-')
                                ? 'backups-destination-edit-dialog'
                            : screen === 'build' && dialog === 'operator-note'
                                ? 'build-note-dialog'
                            : screen === 'build' && dialog?.startsWith('compare-build-')
                                ? 'build-comparison-dialog'
                            : screen === 'build' && dialog === 'build-website-health-checks-dialog'
                                ? 'build-website-health-checks-dialog'
                            : screen === 'account' && dialog === 'account-sign-in-history-dialog'
                                ? 'account-sign-in-history-dialog'
                            : screen === 'account' && dialog === 'account-profile-dialog'
                                ? 'account-profile-dialog'
                            : screen === 'account' && dialog === 'account-audit-dialog'
                                ? 'account-audit-dialog'
                            : screen === 'dashboard' && dialog === 'dashboard-activity'
                                ? 'dashboard-activity-dialog'
                            : screen === 'dashboard' && dialog === 'active-deployments'
                                ? 'dashboard-active-deployments-dialog'
                            : screen === 'dashboard' && dialog === 'system-health'
                                ? 'dashboard-system-health-dialog'
                            : screen === 'dashboard' && dialog === 'active-commands'
                                ? 'dashboard-active-commands-dialog'
                            : screen === 'dashboard' && dialog === 'webhook-activity'
                                ? 'dashboard-webhook-activity-dialog'
                            : screen === 'dashboard' && dialog === 'provisioning'
                                ? 'dashboard-provisioning-dialog'
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
                            : screen === 'gallery-my-reports' && dialog?.startsWith('report-status-')
                                ? 'gallery-my-reports-dialog'
                            : screen === 'repositories' && dialog === 'impact-preview'
                                ? 'repositories-impact-preview-dialog'
                            : screen === 'observability' && fragment === 'operational-incident'
                                ? 'observability-operational-incident-content'
                            : screen === 'observability' && dialog?.startsWith('operational-incident-')
                                ? 'observability-operational-incident-dialog'
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
                                : screen === 'repository-show' && fragment === 'webhook-delivery'
                                    ? 'repository-webhook-delivery-content'
                                : screen === 'repository-show' && dialog?.startsWith('webhook-delivery-')
                                    ? 'repository-show-webhook-delivery-dialog'
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
            if (screen === 'provider-connection-checks' || screen === 'website-health-checks') {
                const result = new URL(route.request().url()).searchParams.get('result');
                if (result) {
                    html = html.replace(`value="${result}"`, `value="${result}" selected`);
                }
            }
            const script = /\/livewire(?:-[^/]+)?\/livewire/.test(html)
                ? ''
                : `<script type="module" src="${alpine}"></script>`;
            html = html.replace('</head>', `<link rel="stylesheet" href="${stylesheet}"><script type="module" src="${signalTheme}"></script>${script}</head>`);
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

test('operational incident timeline opens as a contextual evidence dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/observability', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'Timeline and response', exact: true }).first();
    const triggerHref = await trigger.getAttribute('href');
    const dialog = page.getByRole('dialog', { name: 'Incident timeline', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-operational-incident-content]')).toBeVisible();
    await expect(dialog).toContainText('Fixture incident summary.');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^operational-incident-/);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto(new URL(triggerHref, page.url()).href, { waitUntil: 'networkidle' });
    const directDialog = page.getByRole('dialog', { name: 'Incident timeline', exact: true });
    await expect(directDialog).toBeVisible();
    await expect(directDialog.locator('[data-operational-incident-content]')).toBeVisible();
});

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
    await expect(page.getByRole('dialog', { name: 'Search workspace' })).toBeVisible();
    await page.locator('#command-palette-result-1').click();
    await expect(page.getByRole('dialog', { name: 'Search workspace' })).toBeHidden();
    await expect(dialog).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialUrl.pathname);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('create-application');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
});

test('dashboard workspace search opens in place and renders debounced results', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.route('**/search*', async (route) => {
        const url = new URL(route.request().url());

        if (url.pathname === '/search' && url.searchParams.get('fragment') === 'workspace') {
            return route.fulfill({
                contentType: 'text/html',
                body: '<a href="/projects/1" data-palette-item role="option" class="block p-3">Demo result</a>',
            });
        }

        return route.fallback();
    });
    await page.goto('http://buildpusher.test/dashboard', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'Search workspace', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();

    const dialog = page.getByRole('dialog', { name: 'Search workspace', exact: true });
    await expect(dialog).toBeVisible();
    await expect(page.locator('#command-palette-query')).toBeFocused();
    expect(new URL(page.url()).pathname).toBe(initialPath);

    await page.locator('#command-palette-query').fill('demo');
    await expect(dialog.getByRole('option', { name: 'Demo result', exact: true })).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    expect(new URL(page.url()).pathname).toBe(initialPath);
});

test('provider, repository, and recipe edits open server-rendered dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);

    for (const workflow of [
        { path: 'providers/1', trigger: 'Edit Provider', title: 'Edit provider', query: 'edit-provider' },
        { path: 'repositories/1', trigger: 'Edit', title: 'Edit repository', query: 'edit-repository' },
        { path: 'repositories/1', trigger: 'Manage webhook', title: 'Webhook settings', query: 'repository-webhook-settings' },
        { path: 'servers/1', trigger: 'Edit Display Name', title: 'Edit server display name', query: 'edit-display-name' },
        { path: 'websites/1', trigger: 'Edit Website', title: 'Edit website', query: 'edit-website' },
    ]) {
        await page.goto(`http://buildpusher.test/${workflow.path}`, { waitUntil: 'networkidle' });
        const trigger = page.getByRole('link', { name: workflow.trigger, exact: true }).first();
        const initialPath = new URL(page.url()).pathname;
        await trigger.click();
        const dialog = page.getByRole('dialog', { name: workflow.title, exact: true });
        await expect(dialog).toBeVisible();
        await expect(dialog.locator('form[method="POST"]').first()).toBeVisible();
        if (workflow.query === 'edit-website' || workflow.query === 'edit-repository') {
            await expect(dialog.locator('.ui-input').first()).toBeVisible();
            await expect(dialog.locator('.ui-panel').first()).toBeVisible();
        }
        expect(new URL(page.url()).searchParams.get('dialog')).toBe(workflow.query);
        expect(new URL(page.url()).pathname).toBe(initialPath);
        await expect(dialog.locator('[data-modal-close]')).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    }

    await page.goto('http://buildpusher.test/recipes', { waitUntil: 'networkidle' });
    await expect(page.getByRole('navigation', { name: 'Recipe sections', exact: true })).toBeVisible();
    await expect(page.locator('#recipe-insights')).toBeVisible();
    await expect(page.locator('#recipe-inventory')).toBeVisible();
    const recipeTrigger = page.getByRole('link', { name: 'Edit', exact: true }).first();
    const recipeUrl = new URL(await recipeTrigger.getAttribute('href'), 'http://buildpusher.test');
    const recipeInitialPath = new URL(page.url()).pathname;
    await recipeTrigger.click();
    const recipeDialog = page.getByRole('dialog', { name: 'Edit recipe', exact: true });
    await expect(recipeDialog).toBeVisible();
    await expect(recipeDialog.locator('form[method="POST"]')).toBeVisible();
    await expect(recipeDialog.locator('.ui-input').first()).toBeVisible();
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^edit-recipe-\d+$/);
    expect(new URL(page.url()).pathname).toBe(recipeInitialPath);
    await expect(recipeDialog.locator('[data-modal-close]')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(recipeDialog).toBeHidden();
    await expect(recipeTrigger).toBeFocused();

    await page.goto(`http://buildpusher.test${recipeUrl.pathname}${recipeUrl.search}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Edit recipe', exact: true })).toBeVisible();
});

test('repository webhook delivery details open as a contextual inspector', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/repositories/1', { waitUntil: 'networkidle' });

    let trigger = page.getByRole('link', { name: 'Inspect delivery', exact: true }).first();
    const canonicalRepositoryPath = new URL(await trigger.getAttribute('href'), page.url()).pathname;
    await page.goto(`http://buildpusher.test${canonicalRepositoryPath}`, { waitUntil: 'networkidle' });
    trigger = page.getByRole('link', { name: 'Inspect delivery', exact: true }).first();
    const triggerHref = await trigger.getAttribute('href');
    const dialog = page.getByRole('dialog', { name: 'Webhook delivery', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-webhook-delivery-content]')).toBeVisible();
    await expect(dialog).toContainText('apps/app.php');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^webhook-delivery-/);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto(new URL(triggerHref, page.url()).href, { waitUntil: 'networkidle' });
    const directDialog = page.getByRole('dialog', { name: 'Webhook delivery', exact: true });
    await expect(directDialog).toBeVisible();
    await expect(directDialog.locator('[data-webhook-delivery-content]')).toBeVisible();
});

test('repository deployment and webhook surfaces stay scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/repositories/1', { waitUntil: 'networkidle' });

    await expect(page.getByRole('navigation', { name: 'Repository sections', exact: true })).toBeVisible();
    await expect(page.locator('#repository-overview')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#repository-latest-deployment')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#deployment-webhook')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#deployment-webhook .ui-input')).toHaveCount(4);
    await expect(page.locator('#repository-setup')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#repository-deployment-insights')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#repository-deployment-history')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#repository-information')).toHaveClass(/\bui-panel\b/);
    await expect(page.getByRole('link', { name: 'Export CSV', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'View all deployments', exact: true }).last()).toBeVisible();
});

test('repository deployment impact preview opens and refreshes inside a contextual dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/repositories', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'Preview push impact', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Deployment impact preview', exact: true });
    const documentRequests = [];
    page.on('request', (request) => {
        if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
            documentRequests.push(new URL(request.url()).pathname);
        }
    });

    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-repository-impact-preview-content]')).toBeVisible();
    await expect(dialog).toContainText('App');
    await expect(dialog).toContainText('Affected');
    expect(new URL(page.url()).pathname).toBe('/repositories');
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('impact-preview');
    expect(documentRequests).toEqual([]);

    await dialog.locator('textarea[name="changed_paths"]').fill('apps/app.php');
    await dialog.getByRole('button', { name: 'Preview deployment impact', exact: true }).click();
    await expect(dialog.locator('[data-impact-target]')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe('/repositories');
    expect(documentRequests).toEqual([]);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/repositories?dialog=impact-preview', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Deployment impact preview', exact: true })).toBeVisible();
});

test('build comparison opens as a read-only contextual dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/builds/2', { waitUntil: 'networkidle' });

    let trigger = page.getByRole('link', { name: 'Compare with previous', exact: true });
    const canonicalBuildPath = new URL(await trigger.getAttribute('href'), page.url()).pathname;
    await page.goto(`http://buildpusher.test${canonicalBuildPath}`, { waitUntil: 'networkidle' });
    trigger = page.getByRole('link', { name: 'Compare with previous', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Compare deployments', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-build-comparison-content]')).toBeVisible();
    await expect(dialog).toContainText('Fixture baseline failure');
    await expect(dialog).toContainText('Current status');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    const dialogQuery = new URL(page.url()).searchParams.get('dialog');
    expect(dialogQuery).toMatch(/^compare-build-\d+-\d+$/);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto(`${initialPath}?dialog=${dialogQuery}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Compare deployments', exact: true })).toBeVisible();
});

test('build health history opens as a contextual read-only dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/builds/2', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'View health history', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Health check history', exact: true });
    const expectedPath = new URL(await trigger.getAttribute('data-modal-history-url'), page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('form[data-modal-fragment-form]')).toBeVisible();
    await expect(dialog.locator('#health-dialog-result')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(expectedPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('build-website-health-checks-dialog');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto(`${expectedPath}?dialog=build-website-health-checks-dialog`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Health check history', exact: true })).toBeVisible();
});

test('account sign-in history opens as a contextual read-only dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/account', { waitUntil: 'networkidle' });

    const signInSection = page.locator('#account-sign-ins');
    await signInSection.locator('summary').click();
    const trigger = page.getByRole('link', { name: 'View full history', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Sign-in history', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('form[data-modal-fragment-form]')).toBeVisible();
    await expect(dialog.locator('#sign-in-dialog-method')).toBeVisible();
    await expect(dialog).toContainText('No sign-in history yet.');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('account-sign-in-history-dialog');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/account?dialog=account-sign-in-history-dialog', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Sign-in history', exact: true })).toBeVisible();
});

test('account profile opens as a page-local editor dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/account', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'Edit profile', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Profile information', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[name="name"]')).toBeVisible();
    await expect(dialog.locator('input[name="email"]')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('account-profile-dialog');
    await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/account?dialog=account-profile-dialog', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Profile information', exact: true })).toBeVisible();
});

test('environment evidence health history opens without leaving the investigation context', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/observability/environments/1/context', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'View health history', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Health check history', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('form[data-modal-fragment-form]')).toBeVisible();
    await expect(dialog.locator('#health-dialog-result')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('environment-health-checks-dialog');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/observability/environments/1/context?dialog=environment-health-checks-dialog', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Health check history', exact: true })).toBeVisible();
});

test('environment evidence uses compact mobile sections and local navigation', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/observability/environments/1/context', { waitUntil: 'networkidle' });

    await expect(page.getByRole('navigation', { name: 'Environment evidence sections', exact: true })).toBeVisible();
    await expect(page.locator('[data-observability-context-card]')).toBeVisible();
    await expect(page.locator('#context-deployments')).toBeVisible();
    await expect(page.locator('#context-health')).toBeVisible();
    await expect(page.locator('#context-logs')).toBeVisible();
    await expect(page.locator('#context-incidents')).toBeVisible();
    await expect(page.locator('#environment-context-filters')).toBeVisible();
    await expect(page.locator('#environment-context-filters .ui-input').first()).toBeHidden();
    await page.locator('#environment-context-filters summary').click();
    await expect(page.locator('#environment-context-filters .ui-input').first()).toBeVisible();
});

test('account audit opens as a compact read-only security inspector', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/account', { waitUntil: 'networkidle' });

    const securitySection = page.locator('#account-security-activity');
    await securitySection.locator('summary').click();
    const trigger = page.getByRole('link', { name: 'View full account audit', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Account audit', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-activity-audit-content]')).toBeVisible();
    await expect(dialog).toContainText('No account activity yet');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('account-audit-dialog');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/account?dialog=account-audit-dialog', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Account audit', exact: true })).toBeVisible();
});

test('dashboard activity opens as a compact workspace inspector', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/home', { waitUntil: 'networkidle' });

    const trigger = page.locator('[data-modal-trigger="dashboard-activity-dialog"]');
    const dialog = page.getByRole('dialog', { name: 'Workspace activity', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-activity-history-content]')).toBeVisible();
    await expect(dialog).toContainText('Recent activity');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('dashboard-activity');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/dashboard?dialog=dashboard-activity', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Workspace activity', exact: true })).toBeVisible();
});

test('dashboard active deployments open as a contextual timeline', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/home', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'View active deployments', exact: true }).first();
    const dialog = page.getByRole('dialog', { name: 'Active deployments', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-build-card]')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('active-deployments');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/home?dialog=active-deployments', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Active deployments', exact: true })).toBeVisible();
});

test('dashboard system health opens as a private diagnostic inspector', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/home', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'View system health', exact: true });
    const dialog = page.getByRole('dialog', { name: 'System health', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#system-health-insights')).toBeVisible();
    await expect(dialog).toContainText('Operational');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('system-health');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/home?dialog=system-health', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'System health', exact: true })).toBeVisible();
});

test('system health keeps its diagnostic snapshot scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/system-health', { waitUntil: 'networkidle' });

    await expect(page.locator('#system-health-summary')).toBeVisible();
    await expect(page.locator('#system-health-insights .ui-stat')).toHaveCount(4);
    await expect(page.locator('section[aria-labelledby="system-health-checks"] li .ui-panel')).toHaveCount(2);
    await expect(page.locator('#system-health-help')).toHaveClass(/\bui-panel\b/);
});

test('dashboard active commands open as a bounded status inspector', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/home', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'Open Command Center', exact: true }).first();
    const dialog = page.getByRole('dialog', { name: 'Active server commands', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-command-history-content]')).toBeVisible();
    await expect(dialog).toContainText('Active command history');
    await expect(dialog).not.toContainText('fixture-sensitive-command');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('active-commands');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/home?dialog=active-commands', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Active server commands', exact: true })).toBeVisible();
});

test('dashboard deployment activity opens as a category-scoped inspector', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/home', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'View deployment activity', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Deployment activity', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-activity-history-content]')).toBeVisible();
    await expect(dialog).toContainText('Workspace activity');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('webhook-activity');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/home?dialog=webhook-activity', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Deployment activity', exact: true })).toBeVisible();
});

test('dashboard provisioning opens as an instant snapshot inspector', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/home', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'View provisioning servers', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Infrastructure provisioning', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-dashboard-provisioning-content]')).toBeVisible();
    await expect(dialog).toContainText('Provisioning fixture server');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('provisioning');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/home?dialog=provisioning', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Infrastructure provisioning', exact: true })).toBeVisible();
});

test('provider connection history opens and filters inside a contextual dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/providers/1', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'View all connection checks', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Connection check history', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('form[data-modal-fragment-form]')).toBeVisible();
    await expect(dialog.locator('#connection-dialog-result')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('provider-connection-checks');

    await dialog.locator('#connection-dialog-result').selectOption('failed');
    await dialog.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#connection-dialog-result')).toHaveValue('failed');
    expect(new URL(page.url()).pathname).toBe(initialPath);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('provider detail actions and attached resources stay scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/providers/1', { waitUntil: 'networkidle' });

    await expect(page.getByRole('heading', { name: 'GitHub', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Test connection', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Delete Provider', exact: true })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Provider sections', exact: true })).toBeVisible();
    await expect(page.locator('#provider-overview')).toBeVisible();
    await expect(page.locator('#provider-connection-evidence')).toBeVisible();

    const resources = page.locator('section[aria-labelledby="provider-resources-heading"]');
    await expect(resources).toBeVisible();
    await expect(resources.locator('[data-provider-resource-count="repositories"]')).toHaveText('1');
    await expect(resources.getByRole('link', { name: 'Add Repository', exact: true })).toBeVisible();
    await expect(resources.getByRole('link', { name: /App/ }).first()).toBeVisible();
});

test('standalone provider connection history keeps the shared Signal evidence surface', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/providers/1/connection-checks', { waitUntil: 'networkidle' });

    await expect(page.getByRole('heading', { name: 'Connection check history', exact: true })).toBeVisible();
    await expect(page.getByText('Retained evidence', { exact: true })).toBeVisible();
    await expect(page.locator('#provider-connection-checks-insights')).toBeVisible();
    await expect(page.locator('select[name="result"]')).toHaveValue('');
    expect(new URL(page.url()).pathname).toBe('/providers/1/connection-checks');
});

test('website health history opens and filters inside a contextual dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/websites/1', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'View all health checks', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Health check history', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('form[data-modal-fragment-form]')).toBeVisible();
    await expect(dialog.locator('.ui-input')).toHaveCount(4);
    await expect(dialog.locator('#health-dialog-result')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('website-health-checks');

    await dialog.locator('#health-dialog-result').selectOption('failed');
    await dialog.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(dialog.locator('#health-dialog-result')).toHaveValue('failed');
    expect(new URL(page.url()).pathname).toBe(initialPath);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('website detail keeps provisioning and health evidence in Signal panels', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/websites/1', { waitUntil: 'networkidle' });

    await expect(page.getByRole('heading', { name: 'App', exact: true })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Website sections', exact: true })).toBeVisible();
    await expect(page.locator('#website-information')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#website-operations')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#website-health')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#website-health-insights')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Delete Website', exact: true })).toBeVisible();
});

test('website runtime logs and repositories stay scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/websites/1', { waitUntil: 'networkidle' });

    const runtime = page.locator('#website-runtime-logs');
    await expect(runtime).toHaveClass(/\bui-panel\b/);
    await runtime.locator('summary').click();
    await expect(runtime.getByRole('tablist', { name: 'Log type', exact: true })).toBeVisible();
    await expect(runtime.getByRole('tab')).toHaveCount(2);
    await expect(runtime.getByRole('tab').first()).toHaveAttribute('aria-selected', 'true');
    await expect(runtime.locator('.ui-input')).toHaveCount(4);
    await expect(runtime.locator('.ui-choice')).toHaveCount(2);

    const repositories = page.locator('section[aria-labelledby="attached-repositories-heading"] .ui-panel');
    await expect(repositories).toBeVisible();
    await expect(repositories.getByRole('link', { name: 'Add repository', exact: true })).toBeVisible();
    await expect(repositories.getByRole('link', { name: /App/ }).first()).toBeVisible();
});

test('website deployment history opens as a contextual timeline', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/websites/1', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'Deployment history', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Deployment history', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('list', { name: 'Deployment timeline', exact: true })).toBeVisible();
    await expect(dialog.getByText('Recent deployments', { exact: true })).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('website-deployment-history');
    await expect(dialog.getByRole('link', { name: 'Open full history', exact: true })).toHaveAttribute('href', /builds\?website_id=/);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('server command history opens as a read-only contextual dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/servers/1', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'Command History', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Command history', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-command-execution]').first()).toBeVisible();
    await expect(dialog.getByText('uname -a', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('link', { name: 'Download output', exact: true }).first()).toBeVisible();
    await expect(dialog).not.toContainText('fixture command output');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('server-command-history');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('server detail keeps runtime evidence scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/servers/1', { waitUntil: 'networkidle' });

    await expect(page.getByRole('navigation', { name: 'Server sections', exact: true })).toBeVisible();
    await expect(page.locator('[data-server-overview]')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#server-metrics')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#server-diagnostics')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#server-operations')).toHaveClass(/\bui-panel\b/);
    await page.locator('#server-operations summary').click();
    await expect(page.locator('[data-server-log-console]')).toBeVisible();
    await expect(page.getByText('Setup Information', { exact: true })).toHaveCount(0);
});

test('server command output opens as a lazy retained-output inspector', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/servers/1/commands', { waitUntil: 'networkidle' });

    let trigger = page.getByRole('link', { name: 'View output', exact: true }).first();
    const canonicalCommandsPath = new URL(await trigger.getAttribute('href'), page.url()).pathname;
    await page.goto(`http://buildpusher.test${canonicalCommandsPath}`, { waitUntil: 'networkidle' });
    trigger = page.getByRole('link', { name: 'View output', exact: true }).first();
    await expect(page.locator('section[aria-labelledby="server-command-filters-heading"] .ui-input')).toHaveCount(4);
    await expect(page.locator('#server-commands-insights .ui-stat')).toHaveCount(6);
    await expect(page.locator('[aria-label="Server command history"]').locator('..')).toHaveClass(/\bui-panel\b/);
    const dialog = page.getByRole('dialog', { name: 'Command output', exact: true });
    const initialPath = new URL(page.url()).pathname;
    const triggerHref = await trigger.getAttribute('href');
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-command-output-content]')).toBeVisible();
    await expect(dialog).toContainText('fixture command output');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toMatch(/^server-command-output-/);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto(new URL(triggerHref, page.url()).href, { waitUntil: 'networkidle' });
    const directDialog = page.getByRole('dialog', { name: 'Command output', exact: true });
    await expect(directDialog).toBeVisible();
    await expect(directDialog.locator('[data-command-output-content]')).toBeVisible();
});

test('modal history and contextual cancellation preserve the background document', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/providers/1', { waitUntil: 'networkidle' });

    const trigger = page.getByRole('link', { name: 'Edit Provider', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Edit provider', exact: true });
    const documentRequests = [];
    page.on('request', (request) => {
        if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
            documentRequests.push(new URL(request.url()).pathname);
        }
    });

    await trigger.click();
    await expect(dialog.locator('form[method="POST"]')).toBeVisible();
    await dialog.getByRole('link', { name: 'Cancel', exact: true }).click();
    await expect(dialog).toBeHidden();
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);
    expect(documentRequests).toEqual([]);

    await trigger.click();
    await expect(dialog).toBeVisible();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('edit-provider');

    await page.goBack();
    await expect(dialog).toBeHidden();
    expect(new URL(page.url()).searchParams.has('dialog')).toBe(false);

    await page.goForward();
    await expect(dialog).toBeVisible();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('edit-provider');
});

test('mobile filters remain usable without JavaScript', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);

    const browser = page.context().browser();
    const noScriptContext = await browser.newContext({
        viewport: { width: 390, height: 844 },
        javaScriptEnabled: false,
        colorScheme: 'light',
    });
    const noScriptPage = await noScriptContext.newPage();
    await serveFixtures(noScriptPage);

    await noScriptPage.goto('http://buildpusher.test/providers', { waitUntil: 'domcontentloaded' });
    await expect(noScriptPage.locator('#providers-filters input[name="search"]')).toBeVisible();
    await expect(noScriptPage.getByRole('button', { name: 'Apply filters', exact: true })).toBeVisible();

    await noScriptContext.close();
});

test('mobile provider filters lock background scrolling without a document reload', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/providers', { waitUntil: 'networkidle' });

    await expect(page.getByRole('navigation', { name: 'Provider sections', exact: true })).toBeVisible();
    await expect(page.locator('#providers-insights')).toBeVisible();
    await expect(page.locator('#provider-inventory')).toBeVisible();

    const documentRequests = [];
    page.on('request', (request) => {
        if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
            documentRequests.push(new URL(request.url()).pathname);
        }
    });

    const trigger = page.getByRole('button', { name: 'Filter providers', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Filter providers', exact: true });
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('data-filter-dialog', '');
    await expect(page.locator('html')).toHaveAttribute('data-modal-open', '');
    await expect.poll(() => page.evaluate(() => getComputedStyle(document.documentElement).overflow)).toBe('hidden');
    expect(documentRequests).toEqual([]);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(page.locator('html')).not.toHaveAttribute('data-modal-open', '');
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

    await expect(page.getByRole('navigation', { name: 'Gallery sections', exact: true })).toBeVisible();
    await expect(page.locator('#gallery-safety')).toBeVisible();
    await expect(page.locator('#gallery-inventory')).toBeVisible();

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

    await page.goto('http://buildpusher.test/gallery/1', { waitUntil: 'networkidle' });
    await expect(page.getByRole('navigation', { name: 'Recipe sections', exact: true })).toBeVisible();
    await expect(page.locator('#recipe-details-insights')).toBeVisible();
    await expect(page.locator('#gallery-rating')).toBeVisible();
    await expect(page.locator('#gallery-feedback')).toBeVisible();
    await expect(page.locator('#gallery-script')).toBeVisible();
});

test('mobile forms keep focused fields reachable and lazy dialog content exposes busy state', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page, { delays: { '/gallery/1/script': 500 } });
    await page.goto('http://buildpusher.test/provider-create', { waitUntil: 'networkidle' });

    const token = page.locator('#token');
    await expect(token).toHaveCSS('font-size', '16px');
    await expect(token).toHaveCSS('scroll-margin-block-start', '64px');
    await expect(page.locator('#provider-errors')).toHaveCount(0);

    await page.goto('http://buildpusher.test/gallery', { waitUntil: 'networkidle' });

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

test('every rendered link and modal hook has a usable destination', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);

    for (const screen of modalAuditScreens) {
        await page.goto(`http://buildpusher.test/${screen}`, { waitUntil: 'networkidle' });

        const audit = await page.evaluate(() => ({
            links: [...document.querySelectorAll('a')].map((link) => ({
                href: link.getAttribute('href'),
                label: link.textContent?.trim().replace(/\s+/g, ' ').slice(0, 80),
            })),
            dialogs: [...document.querySelectorAll('dialog[id]')].map((dialog) => ({
                id: dialog.id,
                isNativeSheet: dialog.matches('[data-modal-sheet]'),
            })),
            triggers: [...document.querySelectorAll('[data-modal-trigger], [data-filter-dialog-trigger]')].map((trigger) => ({
                id: trigger.getAttribute('data-modal-trigger') || trigger.getAttribute('aria-controls'),
                contentUrl: trigger.getAttribute('data-modal-content-url'),
                label: trigger.textContent?.trim().replace(/\s+/g, ' ').slice(0, 80),
            })),
        }));

        expect(audit.links.filter((link) => !link.href || link.href.trim() === ''), screen).toEqual([]);
        expect(audit.dialogs.filter((dialog) => !dialog.isNativeSheet), screen).toEqual([]);

        const dialogIds = new Set(audit.dialogs.map((dialog) => dialog.id));
        for (const trigger of audit.triggers) {
            expect(trigger.id, `${screen}: ${trigger.label}`).toBeTruthy();
            expect(dialogIds.has(trigger.id), `${screen}: ${trigger.id}`).toBe(true);

            if (trigger.contentUrl) {
                const contentUrl = new URL(trigger.contentUrl, page.url());
                expect(contentUrl.origin, `${screen}: ${trigger.id}`).toBe('http://buildpusher.test');
            }
        }

        for (const link of audit.links) {
            const url = new URL(link.href, page.url());
            expect(['http:', 'https:', 'mailto:', 'tel:'], `${screen}: ${link.label}`).toContain(url.protocol);
            if (['http:', 'https:'].includes(url.protocol) && url.origin === 'http://buildpusher.test') {
                expect(url.pathname, `${screen}: ${link.label}`).toMatch(/^\//);
            }
        }
    }
});

test('rendered modal openers use native sheets and keep page scrolling locked', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);

    for (const screen of modalAuditScreens.filter((screen) => !['landing', 'login', 'pricing'].includes(screen))) {
        await page.goto(`http://buildpusher.test/${screen}`, { waitUntil: 'networkidle' });
        await page.locator('details').evaluateAll((details) => details.forEach((detail) => { detail.open = true; }));

        const dialogIds = await page.locator('dialog[data-modal-sheet]').evaluateAll((dialogs) => dialogs.map((dialog) => dialog.id));
        for (const id of dialogIds) {
            const dialog = page.locator(`#${id}`);
            const trigger = page.locator(`[data-modal-trigger][aria-controls="${id}"]:visible, [data-filter-dialog-trigger][aria-controls="${id}"]:visible`).first();

            if (! await trigger.isVisible().catch(() => false)) {
                continue;
            }

            await trigger.scrollIntoViewIfNeeded();
            await trigger.click();
            await expect(dialog).toBeVisible();
            await expect(page.locator('html')).toHaveAttribute('data-modal-open', '');
            await expect(page.locator('body')).toHaveAttribute('data-modal-open', '');
            await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');

            const pageScrollTop = await page.evaluate(() => document.scrollingElement.scrollTop);
            await page.mouse.wheel(0, 1200);
            await page.waitForTimeout(100);
            const pageScrollAfterWheel = await page.evaluate(() => document.scrollingElement.scrollTop);
            expect(pageScrollAfterWheel, `${screen}: ${id} should keep the page scroll position fixed`).toBe(pageScrollTop);

            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            await page.waitForTimeout(100);
            const lockState = await page.evaluate(() => ({
                html: document.documentElement.hasAttribute('data-modal-open'),
                body: document.body.hasAttribute('data-modal-open'),
                open: [...document.querySelectorAll('dialog[open]')].map((openDialog) => openDialog.id),
            }));
            expect(lockState, `${screen}: ${id} should release the page lock`).toEqual({ html: false, body: false, open: [] });
        }
    }
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
                await expect(page.locator('body')).toHaveCSS('background-color', colorScheme === 'dark' ? 'rgb(23, 25, 28)' : 'rgb(244, 247, 251)');
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), screen).toBe(true);
                const primaryText = page.locator('.text-primary').first();
                await expect(primaryText).toHaveCSS('color', colorScheme === 'dark' ? 'rgb(244, 244, 245)' : 'rgb(16, 24, 40)');
                if (screen === 'login') {
                    await expect(page.locator('#email')).toHaveCSS('border-top-width', '1px');
                    await expect(page.locator('#email')).toHaveCSS('background-color', colorScheme === 'dark' ? 'rgb(34, 36, 40)' : 'rgb(255, 255, 255)');
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
                        const activeSetupStep = page.locator('[data-dashboard-setup-step]:visible');
                        await expect(activeSetupStep).toHaveCount(1);
                        if (width <= 390) {
                            expect((await activeSetupStep.boundingBox()).height).toBeLessThan(220);
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
                    await expect(page.locator('body')).toHaveCSS('background-color', override === 'dark' ? 'rgb(23, 25, 28)' : 'rgb(244, 247, 251)');
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
                    await expect(page.getByRole('dialog', { name: 'Search workspace' })).toBeVisible();
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
                    await expect(page.getByRole('navigation', { name: 'Deployment sections', exact: true })).toBeVisible();
                    await expect(page.locator('#build-summary')).toBeVisible();
                    const evidence = page.locator('#deployment-evidence');
                    const content = evidence.locator('.ui-responsive-details__content');
                    await expect(evidence).toBeVisible();
                    await expect(evidence).toHaveClass(/\bui-panel\b/);
                    await expect(page.locator('#deployment-timeline')).toHaveClass(/\bui-panel\b/);
                    await expect(page.locator('#deployment-log')).toHaveClass(/\bui-panel\b/);
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
                    await expect(brand).toHaveCSS('color', colorScheme === 'dark' ? 'rgb(244, 244, 245)' : 'rgb(16, 24, 40)');

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

                        const roleTrigger = page.getByRole('link', { name: 'Edit role', exact: true }).first();
                        const roleDialog = page.getByRole('dialog', { name: 'Edit member role', exact: true });
                        const roleUrl = new URL(await roleTrigger.getAttribute('href'), page.url());
                        await roleTrigger.click();
                        await expect(roleDialog).toBeVisible();
                        await expect(roleDialog.locator('select[name="role"]')).toHaveValue('developer');
                        expect(new URL(page.url()).searchParams.get('dialog')).toBe(roleUrl.searchParams.get('dialog'));
                        await page.keyboard.press('Escape');
                        await expect(roleDialog).toBeHidden();
                        await expect(roleTrigger).toBeFocused();

                        await page.goto(`http://buildpusher.test/organization?${roleUrl.searchParams.toString()}`, { waitUntil: 'networkidle' });
                        await expect(page.getByRole('dialog', { name: 'Edit member role', exact: true })).toBeVisible();

                        await page.goto('http://buildpusher.test/organization', { waitUntil: 'networkidle' });
                        const notificationPreferences = page.locator('#organization-notification-preferences');
                        if (! await notificationPreferences.evaluate((details) => details.open)) {
                            await notificationPreferences.locator('summary').click();
                        }
                        const preferenceTrigger = page.getByRole('link', { name: 'Edit preferences', exact: true });
                        const preferenceDialog = page.getByRole('dialog', { name: 'Notification preferences', exact: true });
                        await preferenceTrigger.click();
                        await expect(preferenceDialog).toBeVisible();
                        await expect(preferenceDialog.locator('input[name="categories[]"]')).toHaveCount(6);
                        await page.keyboard.press('Escape');
                        await expect(preferenceDialog).toBeHidden();
                        await expect(preferenceTrigger).toBeFocused();
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
    await expect(page.locator('#token')).toHaveClass(/\bui-input\b/);
    await expect(page.locator('#name')).toHaveClass(/\bui-input\b/);
    await expect(page.locator('#description')).toHaveClass(/\bui-input\b/);
    await expect(page.locator('label.ui-choice')).toHaveCount(7);
    await expect(page.locator('#provider-create-dialog .input.secondary')).toHaveCount(0);

    const monitoring = page.locator('#provider-monitoring-settings');
    await expect(monitoring).toHaveClass(/\bui-card\b/);
    const content = monitoring.locator('.ui-responsive-details__content');
    await expect(content).toBeHidden();
    await monitoring.locator('summary').click();
    await expect(content).toBeVisible();
});

test('mobile filters use native bottom-sheet dialogs without changing filter URLs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);

    for (const screen of ['repositories', 'providers', 'websites', 'builds', 'commands', 'activity']) {
        await page.goto(`http://buildpusher.test/${screen}`, { waitUntil: 'networkidle' });

        const filterId = screen === 'builds'
            ? 'deployment-filters'
            : screen === 'commands'
                ? 'command-filters'
                : screen === 'activity'
                    ? 'activity-filters'
                : `${screen}-filters`;
        const filter = page.locator(`#${filterId}`);
        const trigger = page.locator(`[data-filter-dialog-trigger][aria-controls="${filterId}"]`);
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
        const pageScrollTop = await page.evaluate(() => document.scrollingElement.scrollTop);
        await page.mouse.wheel(0, 1200);
        await expect.poll(() => page.evaluate(() => document.scrollingElement.scrollTop)).toBe(pageScrollTop);

        await page.keyboard.press('Escape');
        await expect(filter).not.toHaveAttribute('open', '');
        await expect(page.locator('html')).not.toHaveAttribute('data-modal-open', '');
        await expect(trigger).toBeFocused();
    }
});

test('website inventory keeps Signal filters and mobile resource cards scannable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/websites', { waitUntil: 'networkidle' });

    const filters = page.locator('#websites-filters');
    await expect(filters.locator('.ui-input')).toHaveCount(3);
    await expect(filters.locator('label.ui-choice')).toHaveCount(2);

    const inventory = page.locator('[aria-label="Website inventory"]');
    await expect(inventory).toBeVisible();
    await expect(inventory).toHaveClass(/\bui-panel\b/);
    await expect(inventory.locator('[data-website-card]')).toHaveCount(1);
    await expect(inventory.getByRole('link', { name: 'App', exact: true })).toBeVisible();
});

test('deployment history keeps Signal filters, insights and cards scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/builds', { waitUntil: 'networkidle' });

    const filters = page.locator('#deployment-filters');
    await expect(filters.locator('.ui-input')).toHaveCount(9);
    await expect(filters.locator('label.ui-choice')).toHaveCount(2);
    await expect(filters.locator('.input.secondary')).toHaveCount(0);

    const insights = page.locator('#builds-insights');
    await expect(insights).toBeVisible();
    await expect(insights.locator('.ui-stat')).toHaveCount(6);

    const inventory = page.locator('#deployment-history');
    await expect(inventory).toBeVisible();
    await expect(inventory).toHaveClass(/\bui-panel\b/);
    await expect(inventory.locator('[data-build-card]').first()).toBeVisible();
});

test('command center keeps Signal filters, insights and execution cards scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/commands', { waitUntil: 'networkidle' });

    const filters = page.locator('#command-filters');
    await expect(filters.locator('.ui-input')).toHaveCount(5);
    await expect(filters.locator('label.ui-choice')).toHaveCount(1);
    await expect(filters.locator('.input.secondary')).toHaveCount(0);

    const insights = page.locator('#command-insights');
    await expect(insights).toBeVisible();
    await expect(insights.locator('.ui-stat')).toHaveCount(6);

    const inventory = page.locator('#command-history');
    await expect(inventory).toBeVisible();
    await expect(inventory).toHaveClass(/\bui-panel\b/);
    await expect(inventory.locator('[data-command-execution]').first()).toBeVisible();
});

test('activity keeps Signal filters, insights and event history scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/activity', { waitUntil: 'networkidle' });

    const filters = page.locator('#activity-filters');
    await expect(filters.locator('.ui-input')).toHaveCount(4);
    await expect(filters.locator('.input.secondary')).toHaveCount(0);

    const insights = page.locator('#activity-insights');
    await expect(insights).toBeVisible();
    await expect(insights.locator('.ui-stat')).toHaveCount(7);

    const feed = page.locator('[data-activity-feed]');
    await expect(feed).toBeVisible();
    await expect(feed).toHaveClass(/\bui-panel\b/);
    await expect(feed.locator('[data-activity-event]').first()).toBeVisible();
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

test('organization member roles open in a page-local dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/organization', { waitUntil: 'networkidle' });

    const roleTrigger = page.getByRole('link', { name: 'Edit role', exact: true }).first();
    const roleHref = new URL(await roleTrigger.getAttribute('href'), page.url());
    const roleDialog = page.getByRole('dialog', { name: 'Edit member role', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await roleTrigger.click();
    await expect(roleDialog).toBeVisible();
    await expect(roleDialog.locator('select[name="role"]')).toHaveValue('developer');
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe(roleHref.searchParams.get('dialog'));
    await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');

    await page.keyboard.press('Escape');
    await expect(roleDialog).toBeHidden();
    await expect(roleTrigger).toBeFocused();

    await page.goto(`http://buildpusher.test/organization?${roleHref.searchParams.toString()}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Edit member role', exact: true })).toBeVisible();
});

test('domain actions stay in the page header above the overview on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/domains', { waitUntil: 'networkidle' });

    const actions = page.locator('[data-domain-actions]');
    const insights = page.locator('#domain-insights');
    await expect(actions).toBeVisible();
    await expect(page.getByRole('link', { name: 'Add domain', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Issue temporary domain', exact: true })).toBeVisible();
    await expect(page.locator('#domain-inventory')).toHaveClass(/\bui-inventory-list\b/);

    const actionBottom = await actions.evaluate((element) => element.getBoundingClientRect().bottom);
    const insightTop = await insights.evaluate((element) => element.getBoundingClientRect().top);
    expect(actionBottom).toBeLessThanOrEqual(insightTop);
});

test('server inventory keeps capacity and provisioning rows scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/servers', { waitUntil: 'networkidle' });

    await expect(page.locator('#servers-insights .ui-stat')).toHaveCount(6);
    await expect(page.locator('#servers-filters')).toHaveClass(/\bui-filter-dialog\b/);
    await expect(page.locator('#servers-filters .ui-input')).toHaveCount(2);
    await expect(page.locator('[data-server-card]')).toHaveCount(2);
    await expect(page.locator('[data-server-card]').first().locator('.ui-link')).toBeVisible();
    await expect(page.locator('[data-server-card]').first().locator('.ui-eyebrow')).toHaveCount(4);
});

test('organization notification preferences open in a page-local dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/organization', { waitUntil: 'networkidle' });

    const notificationPreferences = page.locator('#organization-notification-preferences');
    if (! await notificationPreferences.evaluate((details) => details.open)) {
        await notificationPreferences.locator('summary').click();
    }
    const trigger = page.getByRole('link', { name: 'Edit preferences', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Notification preferences', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[name="categories[]"]')).toHaveCount(6);
    await expect(dialog.locator('input[type="checkbox"][name="recoveries"]')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialPath);
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('organization-notification-preferences-dialog');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/organization?dialog=organization-notification-preferences-dialog', { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Notification preferences', exact: true })).toBeVisible();
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
    await expect(editDestinationDialog.locator('form[method="POST"]')).toBeVisible();
    await expect(page.locator('#backup-destination-edit-1 [data-modal-close]')).toBeFocused();
    expect(new URL(page.url()).searchParams.get('dialog')).toBe('edit-destination-1');
    expect(new URL(page.url()).pathname).toBe('/backups');
    await page.keyboard.press('Escape');
    await expect(editDestinationDialog).toBeHidden();
    await expect(editDestinationTrigger).toBeFocused();

    await page.goto(`http://buildpusher.test${editDestinationUrl.pathname}${editDestinationUrl.search}`, { waitUntil: 'networkidle' });
    await expect(page.getByRole('dialog', { name: 'Edit backup destination', exact: true })).toBeVisible();
});

test('backup overview keeps recovery actions scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/backups', { waitUntil: 'networkidle' });

    await expect(page.locator('[data-backup-readiness]')).toBeVisible();
    await expect(page.locator('#backup-recovery-evidence .ui-stat')).toHaveCount(5);
    await expect(page.locator('#backup-destinations')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('[data-backup-destination]')).toHaveCount(1);
    await expect(page.locator('#backup-schedules')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#backup-history')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#backup-history-list details')).toHaveCount(1);
    await expect(page.locator('#backup-history-list .ui-input')).toHaveCount(2);
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

test('scheduled task output opens as a read-only contextual dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/automation', { waitUntil: 'networkidle' });
    await page.locator('details[id^="automation-project-"]').first().locator('summary').click();

    const trigger = page.locator('[data-modal-trigger="automation-task-run-dialog"]').first();
    const dialog = page.getByRole('dialog', { name: 'Scheduled task run output', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-task-run-output]')).toBeVisible();
    await expect(dialog.locator('[data-task-run-output-text]')).toContainText('fixture scheduled task output');
    await expect(dialog.getByRole('link', { name: 'Open raw output', exact: true })).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialPath);
    const dialogQuery = new URL(page.url()).searchParams.get('dialog');
    expect(dialogQuery).toMatch(/^scheduled-task-run-\d+$/);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/automation?dialog=' + dialogQuery, { waitUntil: 'networkidle' });
    await expect(dialog).toBeVisible();
});

test('report status opens as a private contextual dialog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/gallery/my-reports', { waitUntil: 'networkidle' });

    await expect(page.getByRole('navigation', { name: 'Report history sections', exact: true })).toBeVisible();
    await expect(page.locator('#gallery-report-history-insights')).toBeVisible();
    await expect(page.locator('#gallery-report-history-filters')).toHaveCount(1);
    await expect(page.locator('#gallery-report-history')).toBeVisible();

    const trigger = page.locator('[data-modal-trigger="gallery-report-status-dialog"]').first();
    const dialog = page.getByRole('dialog', { name: 'Report status', exact: true });
    const initialPath = new URL(page.url()).pathname;
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-report-status-content]')).toBeVisible();
    await expect(dialog).toContainText('Fixture private report details.');
    await expect(dialog.getByRole('link', { name: 'Open full report status', exact: true })).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(initialPath);
    const dialogQuery = new URL(page.url()).searchParams.get('dialog');
    expect(dialogQuery).toMatch(/^report-status-\d+$/);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.goto('http://buildpusher.test/gallery/my-reports?dialog=' + dialogQuery, { waitUntil: 'networkidle' });
    await expect(dialog).toBeVisible();
});

test('application detail composers use compact accessible dialogs', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/project-detail', { waitUntil: 'networkidle' });

    await expect(page.locator('[data-project-environments]')).toBeVisible();
    await expect(page.locator('[data-project-environment]').first()).toBeVisible();
    await expect(page.locator('[data-project-runtime-controls]').first()).toBeVisible();
    await expect(page.locator('[data-project-add-environment]')).toBeVisible();
    await expect(page.locator('[data-project-previews]')).toBeVisible();
    await expect(page.locator('.ui-local-nav__link', { hasText: 'Environments' })).toBeVisible();

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

        const trigger = triggerName === 'Add environment'
            ? page.locator('#add-environment').getByRole('link', { name: triggerName, exact: true })
            : page.getByRole('link', { name: triggerName, exact: true });
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

test('notification inbox keeps actions and alerts scannable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'light' });
    await serveFixtures(page);
    await page.goto('http://buildpusher.test/notifications', { waitUntil: 'networkidle' });

    await expect(page.locator('#notifications-insights .ui-stat')).toHaveCount(6);
    await expect(page.locator('#notification-list')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('[data-notification-card]')).toHaveCount(2);
    await expect(page.locator('#notification-bulk-form')).toHaveClass(/\bui-panel\b/);
    await expect(page.locator('#notification-filters .ui-input')).toHaveCount(6);
    await expect(page.locator('#notification-saved-filters')).toBeVisible();
});
