const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const tracker = fs.readFileSync(path.resolve(__dirname, '../../public/tracker/v1.js'), 'utf8');
const siteOrigin = 'https://example.test';
const analyticsOrigin = 'https://analytics.test';
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

async function loadTracker(page, {
    consent = 'true',
    optOut = false,
    responseStatuses = [],
    disableCryptoUuid = false,
    siteId = 'site123',
} = {}) {
    const attempts = [];

    if (disableCryptoUuid) {
        await page.addInitScript(() => {
            Object.defineProperty(window.crypto, 'randomUUID', { configurable: true, value: undefined });
            Object.defineProperty(window.crypto, 'getRandomValues', { configurable: true, value: undefined });
        });
    }

    await page.route(`${analyticsOrigin}/tracker/v1.js`, (route) => route.fulfill({
        status: 200,
        contentType: 'text/javascript',
        body: tracker,
    }));

    await page.route(`${analyticsOrigin}/api/v1/collect/**`, async (route) => {
        const request = route.request();
        const origin = request.headers().origin || siteOrigin;
        const cors = {
            'Access-Control-Allow-Origin': origin,
            'Access-Control-Allow-Methods': 'POST, OPTIONS',
            'Access-Control-Allow-Headers': 'Content-Type',
            'Access-Control-Max-Age': '600',
        };

        if (request.method() === 'OPTIONS') {
            return route.fulfill({ status: 204, headers: cors, body: '' });
        }

        attempts.push(JSON.parse(request.postData() || '{}'));
        const status = responseStatuses.length ? responseStatuses.shift() : 202;

        return route.fulfill({ status, headers: cors, contentType: 'application/json', body: '{"accepted":1}' });
    });

    await page.route(`${siteOrigin}/**`, (route) => route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: `<!doctype html>
            <html><head><script>
                window.analyticsConsent = function () { return ${consent}; };
                window.buildpusherAnalyticsOptOut = ${optOut ? 'true' : 'false'};
            </script></head><body>
                <script src="${analyticsOrigin}/tracker/v1.js" data-site="${siteId}" data-consent="analyticsConsent"></script>
            </body></html>`,
    }));

    await page.goto(`${siteOrigin}/pricing?utm_source=release`);

    return { attempts };
}

function eventsFrom(attempts) {
    return attempts.flatMap((attempt) => attempt.events || []);
}

test('sends valid anonymous identities, tracks SPA navigation and retries the same event', async ({ page }) => {
    const { attempts } = await loadTracker(page, { responseStatuses: [503, 202], disableCryptoUuid: true });

    await expect.poll(() => attempts.length).toBe(2);

    const automaticPageview = eventsFrom(attempts)[0];
    const retriedPageview = eventsFrom(attempts)[1];

    expect(automaticPageview.id).toMatch(uuid);
    expect(automaticPageview.visitor).toMatch(uuid);
    expect(automaticPageview.session).toMatch(uuid);
    expect(retriedPageview.id).toBe(automaticPageview.id);

    await page.evaluate(() => window.buildpusher.track('signup', { name: 'overridden', email: 'private@example.test' }));
    await expect.poll(() => eventsFrom(attempts).length).toBe(3);

    const customEvent = eventsFrom(attempts)[2];
    expect(customEvent.properties.name).toBe('signup');
    expect(customEvent.visitor).toBe(automaticPageview.visitor);
    expect(customEvent.session).toBe(automaticPageview.session);

    await page.evaluate(() => window.history.pushState({}, '', '/checkout'));
    await expect.poll(() => eventsFrom(attempts).length).toBe(4);

    const routePageview = eventsFrom(attempts)[3];
    expect(routePageview.path).toBe('/checkout');
    expect(routePageview.visitor).toBe(automaticPageview.visitor);
    expect(routePageview.session).toBe(automaticPageview.session);

    await page.reload();
    await expect.poll(() => eventsFrom(attempts).length).toBe(5);

    const reloadPageview = eventsFrom(attempts)[4];
    expect(reloadPageview.visitor).toBe(automaticPageview.visitor);
    expect(reloadPageview.session).toBe(automaticPageview.session);
    await expect.poll(() => page.evaluate(() => Object.keys(localStorage))).toContain('buildpusher:v1:visitor:site123');
});

test('does not write visitor storage or send events without consent or after opt-out', async ({ page }) => {
    const denied = await loadTracker(page, { consent: 'false' });

    await page.evaluate(() => window.buildpusher.track('signup'));
    await page.waitForTimeout(150);
    expect(denied.attempts).toHaveLength(0);
    expect(await page.evaluate(() => Object.keys(localStorage))).toEqual([]);
    expect(await page.evaluate(() => Object.keys(sessionStorage))).toEqual([]);

    const optedOutPage = await page.context().newPage();
    const optedOut = await loadTracker(optedOutPage, { optOut: true });

    await optedOutPage.evaluate(() => window.buildpusher.track('signup'));
    await optedOutPage.waitForTimeout(150);
    expect(optedOut.attempts).toHaveLength(0);
    expect(await optedOutPage.evaluate(() => Object.keys(localStorage))).toEqual([]);
    expect(await optedOutPage.evaluate(() => Object.keys(sessionStorage))).toEqual([]);
});

test('keeps browser identities separate for sites hosted on the same origin', async ({ page }) => {
    const first = await loadTracker(page, { siteId: 'site123' });
    await expect.poll(() => first.attempts.length).toBe(1);
    const firstVisitor = eventsFrom(first.attempts)[0].visitor;

    const secondPage = await page.context().newPage();
    const second = await loadTracker(secondPage, { siteId: 'site456' });
    await expect.poll(() => second.attempts.length).toBe(1);

    const secondVisitor = eventsFrom(second.attempts)[0].visitor;
    expect(secondVisitor).toMatch(uuid);
    expect(secondVisitor).not.toBe(firstVisitor);
    expect(await page.evaluate(() => Object.keys(localStorage))).toEqual([
        'buildpusher:v1:visitor:site123',
        'buildpusher:v1:visitor:site456',
    ]);
});
