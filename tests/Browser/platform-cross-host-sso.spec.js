const { test, expect, chromium } = require('@playwright/test');
const { execFileSync, spawn } = require('node:child_process');
const fs = require('node:fs');
const https = require('node:https');
const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const fixtureDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'buildpusher-platform-sso-'));
const databasePath = path.join(fixtureDirectory, 'core.sqlite');
const productHosts = [
    'deployer.buildpusher.test',
    'monitor.buildpusher.test',
    'analytics.buildpusher.test',
];
let applicationServer;
let httpsProxy;
let browser;
let serverOutput = '';
let upstreamPort;
let httpsPort;
let authOrigin;
let dashboardOrigin;
const productOrigins = new Map();

async function findAvailablePorts(count) {
    const listeners = Array.from({ length: count }, () => net.createServer());
    const ports = [];

    try {
        for (const listener of listeners) {
            await new Promise((resolve, reject) => {
                listener.once('error', reject);
                listener.listen(0, '127.0.0.1', resolve);
            });
            ports.push(listener.address().port);
        }
    } finally {
        await Promise.all(listeners.map((listener) => new Promise((resolve) => {
            if (!listener.listening) {
                resolve();
                return;
            }
            listener.close(resolve);
        })));
    }

    return ports;
}

function requestLogin(host) {
    return new Promise((resolve, reject) => {
        const request = https.get({
            host: '127.0.0.1',
            port: httpsPort,
            path: '/login',
            rejectUnauthorized: false,
            servername: host,
            headers: { Host: `${host}:${httpsPort}` },
        }, (response) => {
            response.resume();
            response.once('end', () => resolve(response.statusCode));
        });

        request.once('error', reject);
        request.setTimeout(2_000, () => request.destroy(new Error('Timed out waiting for the isolated Laravel server.')));
    });
}

function expectExchangeIssuer(exchange, issuerOrigin) {
    expect([issuerOrigin, 'null']).toContain(exchange.origin);
    if (exchange.origin === 'null') {
        expect(exchange.referer).toBe(`${issuerOrigin}/`);
    }
}

async function expectDashboard(page, dashboardUrl, navigationLog) {
    try {
        await expect(page).toHaveURL(dashboardUrl);
    } catch (error) {
        const cookies = await page.context().cookies();
        const sessionCookieHosts = cookies
            .filter((cookie) => cookie.name === 'buildpusher_sso_browser')
            .map((cookie) => cookie.domain);
        throw new Error([
            error.message,
            `Final URL: ${page.url()}`,
            `Session cookie hosts: ${sessionCookieHosts.join(', ') || '(none)'}`,
            `Navigation responses: ${navigationLog.join(' | ') || '(none)'}`,
        ].join('\n'));
    }
}

async function waitForApplication() {
    const deadline = Date.now() + 30_000;
    let lastStatus = null;

    while (Date.now() < deadline) {
        if (applicationServer.exitCode !== null) {
            throw new Error(`The isolated Laravel server exited early.\n${serverOutput}`);
        }

        try {
            lastStatus = await requestLogin('auth.buildpusher.test');
            if (lastStatus === 200) {
                return;
            }
        } catch {
            // Keep polling while PHP finishes its first boot.
        }

        await new Promise((resolve) => setTimeout(resolve, 200));
    }

    throw new Error(`The isolated auth host did not become ready (last status: ${lastStatus}).\n${serverOutput}`);
}

function processEnvironment() {
    const portSuffix = `:${httpsPort}`;
    const storageDirectory = path.join(fixtureDirectory, 'storage');
    const cacheDirectory = path.join(fixtureDirectory, 'bootstrap-cache');
    const domainOrigins = [
        'auth.buildpusher.test',
        'buildpusher.test',
        ...productHosts,
    ];

    fs.mkdirSync(path.join(storageDirectory, 'sessions'), { recursive: true });
    fs.mkdirSync(cacheDirectory, { recursive: true });

    return {
        ...process.env,
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        APP_KEY: 'base64:byBqsQ1u9aWNrnP/znQmuEfdtA3RnhRsNQYEzuD0B2g=',
        APP_URL: `https://buildpusher.test${portSuffix}`,
        APP_CONFIG_CACHE: path.join(cacheDirectory, 'config.php'),
        APP_EVENTS_CACHE: path.join(cacheDirectory, 'events.php'),
        APP_PACKAGES_CACHE: path.join(cacheDirectory, 'packages.php'),
        APP_ROUTES_CACHE: path.join(cacheDirectory, 'routes.php'),
        APP_SERVICES_CACHE: path.join(cacheDirectory, 'services.php'),
        BCRYPT_ROUNDS: '4',
        BROWSER_SSO_DATABASE: databasePath,
        CACHE_STORE: 'array',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: databasePath,
        DB_DEFAULT_CONNECTION: 'core',
        CORE_DB_CONNECTION: 'sqlite',
        CORE_DB_DATABASE: databasePath,
        DEPLOYER_DB_CONNECTION: 'sqlite',
        DEPLOYER_DB_DATABASE: path.join(fixtureDirectory, 'deployer.sqlite'),
        MONITOR_DB_CONNECTION: 'sqlite',
        MONITOR_DB_DATABASE: path.join(fixtureDirectory, 'monitor.sqlite'),
        ANALYTICS_DB_CONNECTION: 'sqlite',
        ANALYTICS_DB_DATABASE: path.join(fixtureDirectory, 'analytics.sqlite'),
        PLATFORM_DASHBOARD_HOST: 'buildpusher.test',
        PLATFORM_DASHBOARD_URL: `https://buildpusher.test${portSuffix}`,
        PLATFORM_AUTH_HOST: 'auth.buildpusher.test',
        PLATFORM_AUTH_URL: `https://auth.buildpusher.test${portSuffix}`,
        DEPLOYER_HOST: productHosts[0],
        DEPLOYER_URL: `https://${productHosts[0]}${portSuffix}`,
        DEPLOYER_AUTH_AUTHORITY: 'core',
        MONITOR_ENABLED: 'true',
        MONITOR_HOST: productHosts[1],
        MONITOR_URL: `https://${productHosts[1]}${portSuffix}`,
        MONITOR_AUTH_AUTHORITY: 'core',
        ANALYTICS_ENABLED: 'true',
        ANALYTICS_HOST: productHosts[2],
        ANALYTICS_URL: `https://${productHosts[2]}${portSuffix}`,
        ANALYTICS_AUTH_AUTHORITY: 'core',
        QUEUE_CONNECTION: 'sync',
        LOG_CHANNEL: 'stderr',
        MAIL_MAILER: 'array',
        SESSION_COOKIE: 'buildpusher_sso_browser',
        SESSION_DOMAIN: '',
        SESSION_DRIVER: 'file',
        SESSION_FILES: path.join(storageDirectory, 'sessions'),
        SESSION_SAME_SITE: 'lax',
        SESSION_SECURE_COOKIE: 'true',
        TRUSTED_HOSTS: domainOrigins.join(','),
        REGISTRATION_ENABLED: 'false',
        TELESCOPE_ENABLED: 'false',
    };
}

test.beforeAll(async () => {
    [upstreamPort, httpsPort] = await findAvailablePorts(2);
    const domainHosts = [
        'auth.buildpusher.test',
        'buildpusher.test',
        ...productHosts,
    ];
    const keyPath = path.join(fixtureDirectory, 'tls.key');
    const certificatePath = path.join(fixtureDirectory, 'tls.crt');
    execFileSync('openssl', [
        'req', '-x509', '-newkey', 'rsa:2048', '-sha256', '-nodes',
        '-keyout', keyPath,
        '-out', certificatePath,
        '-days', '1',
        '-subj', '/CN=buildpusher.test',
        '-addext', `subjectAltName=${domainHosts.map((host) => `DNS:${host}`).join(',')}`,
    ], { stdio: 'pipe' });

    authOrigin = `https://auth.buildpusher.test:${httpsPort}`;
    dashboardOrigin = `https://buildpusher.test:${httpsPort}`;
    productHosts.forEach((host) => productOrigins.set(host.split('.')[0], `https://${host}:${httpsPort}`));

    const env = processEnvironment();
    fs.writeFileSync(databasePath, '');
    try {
        execFileSync(process.env.BROWSER_PHP_BINARY || 'php', [
            'vendor/bin/phpunit',
            '--no-progress',
            'tests/Browser/fixtures/PlatformSsoBrowserFixtureTest.php',
        ], {
            cwd: root,
            env,
            timeout: 120_000,
            stdio: 'pipe',
        });
    } catch (error) {
        throw new Error(`Could not prepare the isolated Core SSO fixture.\n${error.stdout?.toString() ?? ''}\n${error.stderr?.toString() ?? ''}`);
    }

    applicationServer = spawn(process.env.BROWSER_PHP_BINARY || 'php', [
        '-S',
        `127.0.0.1:${upstreamPort}`,
        '-t',
        path.join(root, 'public'),
        path.join(root, 'tests/Browser/fixtures/platform-sso-server.php'),
    ], {
        cwd: root,
        env,
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    applicationServer.stdout.on('data', (chunk) => { serverOutput += chunk.toString(); });
    applicationServer.stderr.on('data', (chunk) => { serverOutput += chunk.toString(); });

    httpsProxy = https.createServer({
        key: fs.readFileSync(keyPath),
        cert: fs.readFileSync(certificatePath),
    }, (request, response) => {
        const forwardedHost = request.headers.host;
        const upstream = http.request({
            hostname: '127.0.0.1',
            port: upstreamPort,
            method: request.method,
            path: request.url,
            headers: {
                ...request.headers,
                host: forwardedHost,
                'x-forwarded-for': request.socket.remoteAddress ?? '127.0.0.1',
                'x-forwarded-host': forwardedHost,
                'x-forwarded-port': String(httpsPort),
                'x-forwarded-proto': 'https',
            },
        }, (upstreamResponse) => {
            response.writeHead(upstreamResponse.statusCode ?? 502, upstreamResponse.headers);
            upstreamResponse.pipe(response);
        });

        upstream.once('error', (error) => {
            response.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
            response.end(`The isolated Laravel upstream is unavailable: ${error.message}`);
        });
        request.pipe(upstream);
    });
    await new Promise((resolve, reject) => {
        httpsProxy.once('error', reject);
        httpsProxy.listen(httpsPort, '127.0.0.1', resolve);
    });
    await waitForApplication();
});

test.afterAll(async () => {
    if (browser) {
        await browser.close();
    }

    if (httpsProxy?.listening) {
        await new Promise((resolve) => httpsProxy.close(resolve));
    }

    if (applicationServer && applicationServer.exitCode === null) {
        applicationServer.kill('SIGTERM');
        await new Promise((resolve) => applicationServer.once('exit', resolve));
    }

    fs.rmSync(fixtureDirectory, { recursive: true, force: true });
});

test('Core login establishes host-only sessions across Deployer, Monitor, Analytics, and logout revokes each one', async () => {
    const launchOptions = {
        headless: true,
        args: [
            '--no-proxy-server',
            `--host-resolver-rules=MAP auth.buildpusher.test 127.0.0.1,MAP buildpusher.test 127.0.0.1,MAP ${productHosts[0]} 127.0.0.1,MAP ${productHosts[1]} 127.0.0.1,MAP ${productHosts[2]} 127.0.0.1`,
        ],
    };

    if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) {
        launchOptions.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
    }

    browser = await chromium.launch(launchOptions);
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: { width: 1280, height: 900 },
    });
    const page = await context.newPage();
    const exchangeRequests = [];
    const navigationLog = [];
    const browserErrors = [];

    page.setDefaultNavigationTimeout(30_000);
    page.on('console', (message) => {
        if (message.type() === 'error') {
            browserErrors.push(message.text());
        }
    });
    page.on('request', (request) => {
        const url = new URL(request.url());
        if (url.pathname === '/__platform/sso/exchange') {
            exchangeRequests.push({
                host: url.hostname,
                method: request.method(),
                origin: request.headers().origin,
                referer: request.headers().referer,
            });
        }
    });
    page.on('response', (response) => {
        const request = response.request();
        const url = new URL(response.url());
        if (request.resourceType() === 'document' || url.pathname === '/__platform/sso/exchange') {
            const location = response.headers()['location'];
            const destination = location ? new URL(location, response.url()) : null;
            navigationLog.push(`${request.method()} ${url.host}${url.pathname} ${response.status()}${destination ? ` -> ${destination.host}${destination.pathname}` : ''}`);
        }
    });
    page.on('requestfailed', (request) => {
        const url = new URL(request.url());
        if (request.resourceType() === 'document' || url.pathname.includes('logout')) {
            navigationLog.push(`${request.method()} ${url.host}${url.pathname} failed=${request.failure()?.errorText ?? 'unknown'}`);
        }
    });

    for (const [index, productHost] of productHosts.entries()) {
        const exchangeStart = exchangeRequests.length;
        const product = productHost.split('.')[0];
        const productOrigin = productOrigins.get(product);
        const productReturn = `${productOrigin}/__platform/sso/issue?return_to=${encodeURIComponent(`${dashboardOrigin}/workspaces`)}`;
        const loginUrl = `${authOrigin}/login?return_to=${encodeURIComponent(productReturn)}`;

        await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });

        if (index === 0) {
            await page.getByLabel('Email address').fill('browser-sso@example.test');
            await page.getByLabel('Password').fill('correct horse battery staple');
            await page.getByRole('button', { name: 'Sign in' }).click();
        }

        await expectDashboard(page, `${dashboardOrigin}/workspaces`, navigationLog);
        await expect(page.getByRole('heading', { name: 'Your workspaces' })).toBeVisible();

        const productExchange = exchangeRequests
            .slice(exchangeStart)
            .find((request) => request.host === productHost && request.method === 'POST');
        expect(productExchange, `Expected an SSO exchange POST to ${productHost}`).toBeTruthy();
        expectExchangeIssuer(productExchange, authOrigin);

        if (index === 0) {
            const dashboardExchange = exchangeRequests
                .slice(exchangeStart)
                .find((request) => request.host === 'buildpusher.test' && request.method === 'POST');
            expect(dashboardExchange, 'Expected the first product to establish the dashboard host session').toBeTruthy();
            expectExchangeIssuer(dashboardExchange, productOrigin);
        }
    }

    const cookies = await context.cookies();
    const sessionCookies = cookies.filter((cookie) => cookie.name === 'buildpusher_sso_browser');
    const expectedCookieHosts = [
        'auth.buildpusher.test',
        'buildpusher.test',
        ...productHosts,
    ];

    for (const host of expectedCookieHosts) {
        const cookie = sessionCookies.find((candidate) => candidate.domain === host);
        expect(cookie, `Expected a host-only session cookie for ${host}`).toBeTruthy();
        expect(cookie.domain.startsWith('.')).toBe(false);
        expect(cookie.secure, `Expected a Secure session cookie for ${host}`).toBe(true);
    }

    const accountMenu = page.locator('details').filter({
        has: page.locator('summary[aria-label^="Account menu for"]'),
    });
    await accountMenu.locator('summary').click();
    await expect(accountMenu).toHaveAttribute('open', '');
    const logoutForm = accountMenu.locator(`form[action="${dashboardOrigin}/core/logout"]`);
    await expect(logoutForm).toHaveCount(1);
    await expect(logoutForm.getByRole('button', { name: 'Log out' })).toBeVisible();
    const logoutResponsePromise = page.waitForResponse(
        (response) => response.url() === `${dashboardOrigin}/core/logout`,
        { timeout: 15_000 },
    );
    let logoutResponse;
    const logoutDocument = await page.evaluate(() => ({
        origin: window.location.origin,
        base: document.baseURI,
        action: document.querySelector('form[action$="/core/logout"]')?.action ?? null,
        policy: document.querySelector('meta[http-equiv="Content-Security-Policy"]')?.content ?? null,
    }));
    try {
        [logoutResponse] = await Promise.all([
            logoutResponsePromise,
            logoutForm.getByRole('button', { name: 'Log out' }).click({ timeout: 15_000 }),
        ]);
    } catch (error) {
        const logoutServerRequests = serverOutput.split('\n').filter((line) => line.includes('/core/logout'));
        throw new Error([
            error.message,
            `Final URL: ${page.url()}`,
            `Logout document: ${JSON.stringify(logoutDocument)}`,
            `Recent navigation responses: ${navigationLog.slice(-12).join(' | ') || '(none)'}`,
            `Browser errors: ${browserErrors.slice(-5).join(' | ') || '(none)'}`,
            `Server logout requests: ${logoutServerRequests.join(' | ') || '(none)'}`,
        ].join('\n'));
    }

    expect(logoutResponse.status()).toBe(302);
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    const deployerOrigin = productOrigins.get('deployer');
    await page.goto(`${deployerOrigin}/__platform/sso/issue?return_to=${encodeURIComponent(`${dashboardOrigin}/workspaces`)}`);
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Your workspaces' })).toHaveCount(0);

    await context.close();
});
