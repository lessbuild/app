const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const devices = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'desktop', width: 1440, height: 1000 },
];

const publicPaths = ['/', '/pricing', '/status', '/login', '/register', '/request-access', '/forgot-password', '/status/demo-status'];
const authenticatedSeeds = [
    '/home', '/projects', '/projects/create', '/system-health', '/observability', '/backups',
    '/activity', '/commands', '/websites', '/websites/create', '/servers', '/servers/create',
    '/builds', '/repositories', '/repositories/create', '/notifications', '/automation',
    '/providers', '/providers/create', '/recipes', '/recipes/create', '/gallery',
    '/gallery/my-reports', '/gallery/reports/inbox', '/organization', '/account',
    '/account/sign-ins', '/search?q=demo',
];

const excluded = [
    /^\/api(?:\/|$)/, /^\/_ignition(?:\/|$)/, /^\/livewire(?:\/|$)/,
    /^\/telescope(?:\/|$)/, /^\/log-viewer(?:\/|$)/, /^\/auth\/social(?:\/|$)/,
    /^\/logout(?:\/|$)/, /\/export(?:\/|$)/, /\/download(?:\/|$)/,
    /\/log(?:\/|$)/, /\/output(?:\/|$)/, /\/report\.json$/,
    /\/logs(?:\/|$)/,
    /\/provisioning-log(?:\/|$)/,
    /\/report(?:\/|$)/,
    /^\/stripe(?:\/|$)/, /^\/billing(?:\/|$)/,
];

const slug = (url) => {
    const parsed = new URL(url);
    const suffix = parsed.search ? `-${parsed.searchParams.toString()}` : '';
    return `${parsed.pathname === '/' ? 'home-page' : parsed.pathname.slice(1)}${suffix}`
        .replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').slice(0, 150) || 'page';
};

const canonical = (href, baseURL) => {
    try {
        const url = new URL(href, baseURL);
        const base = new URL(baseURL);
        if (url.origin !== base.origin || excluded.some((pattern) => pattern.test(url.pathname))) return null;
        if (!['http:', 'https:'].includes(url.protocol)) return null;
        url.hash = '';
        if (url.pathname !== '/search') url.search = '';
        return url.toString();
    } catch {
        return null;
    }
};

const routeKey = (url) => {
    const parsed = new URL(url);
    const route = parsed.pathname.replace(/\/\d+(?=\/|$)/g, '/:id');
    return parsed.pathname === '/search' ? `${route}?${parsed.searchParams.toString()}` : route;
};

async function inspect(page, target, outputDir, errors, layoutIssues) {
    const response = await page.goto(target, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(100);
    expect(response, `No document response for ${target}`).not.toBeNull();
    expect(response.status(), `${target} returned ${response.status()}`).toBeLessThan(400);
    await expect(page.locator('body')).not.toBeEmpty();

    const layout = await page.evaluate(() => ({
        documentWidth: document.documentElement.scrollWidth,
        viewportWidth: document.documentElement.clientWidth,
        bodyWidth: document.body.scrollWidth,
    }));
    if (Math.max(layout.documentWidth, layout.bodyWidth) > layout.viewportWidth + 2) {
        layoutIssues.push(`${target} has horizontal overflow: ${JSON.stringify(layout)}`);
    }

    if (process.env.BROWSER_SCREENSHOTS === '1') {
        await page.screenshot({ path: path.join(outputDir, `${slug(page.url())}.png`), fullPage: true });
    }
    const links = await page.locator('a[href]').evaluateAll((anchors) => anchors.map((anchor) => anchor.href));
    return [...new Set(links.map((href) => canonical(href, page.url())).filter(Boolean))];
}

for (const device of devices) {
    test(`${device.name}: every product page renders cleanly`, async ({ page: initialPage, baseURL }) => {
        let page = initialPage;
        await page.setViewportSize({ width: device.width, height: device.height });
        const outputDir = path.resolve('test-results/visual-audit', device.name);
        fs.mkdirSync(outputDir, { recursive: true });

        const runtimeErrors = [];
        const layoutIssues = [];
        const observe = (observedPage) => {
            observedPage.on('pageerror', (error) => runtimeErrors.push(`pageerror on ${observedPage.url()}: ${error.message}`));
            observedPage.on('console', (message) => {
                if (message.type() === 'error') runtimeErrors.push(`console on ${observedPage.url()}: ${message.text()}`);
            });
            observedPage.on('response', (response) => {
                if (response.status() >= 400) runtimeErrors.push(`response ${response.status()}: ${response.url()}`);
            });
        };
        observe(page);

        const visited = new Set();
        const visitedRoutes = new Set();
        for (const pathname of publicPaths) {
            const target = new URL(pathname, baseURL).toString();
            await inspect(page, target, outputDir, runtimeErrors, layoutIssues);
            const normalized = canonical(target, baseURL);
            visited.add(normalized);
            visitedRoutes.add(routeKey(normalized));
        }

        await page.goto(new URL('/login', baseURL).toString());
        await page.locator('#email').fill('ncorkish@icloud.com');
        await page.locator('#password').fill('password');
        await Promise.all([
            page.waitForURL((url) => url.pathname === '/home'),
            page.getByRole('button', { name: 'Login' }).click(),
        ]);

        const navigation = page.locator('#primary-navigation');
        if (device.width < 1024) {
            await page.getByRole('button', { name: 'Toggle navigation' }).click();
            await expect(navigation).toBeVisible();
            await expect(navigation.getByRole('link', { name: 'Applications' })).toBeVisible();
            await navigation.getByRole('link', { name: 'Settings' }).scrollIntoViewIfNeeded();
            await expect(navigation.getByRole('link', { name: 'Settings' })).toBeVisible();
            await page.getByRole('button', { name: 'Close navigation', exact: true }).click();
            await expect(navigation).toBeHidden();
        } else {
            await expect(navigation).toBeVisible();
            await expect(page.getByRole('button', { name: 'Toggle navigation' })).toBeHidden();
        }

        const queue = authenticatedSeeds.map((pathname) => new URL(pathname, baseURL).toString());
        while (queue.length) {
            const target = canonical(queue.shift(), baseURL);
            if (!target || visitedRoutes.has(routeKey(target))) continue;
            visited.add(target);
            visitedRoutes.add(routeKey(target));
            const discovered = await inspect(page, target, outputDir, runtimeErrors, layoutIssues);
            for (const link of discovered) {
                if (!visitedRoutes.has(routeKey(link)) && !queue.includes(link)) queue.push(link);
            }
            expect(visited.size, 'Crawler exceeded its safety limit').toBeLessThan(140);
            if (visited.size % 12 === 0 && queue.length) {
                const replacement = await page.context().newPage();
                await replacement.setViewportSize({ width: device.width, height: device.height });
                observe(replacement);
                await page.close();
                page = replacement;
            }
        }

        fs.writeFileSync(
            path.join(outputDir, 'manifest.json'),
            JSON.stringify({ device, pages: [...visited].sort(), runtimeErrors, layoutIssues }, null, 2),
        );
        expect(runtimeErrors, runtimeErrors.join('\n')).toEqual([]);
        expect(layoutIssues, layoutIssues.join('\n')).toEqual([]);
        expect(visited.size, 'Expected broad route coverage from the demo workspace').toBeGreaterThan(40);
    });
}
