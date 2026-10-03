// The Audit browser: a headless Chromium that a PHP job drives one command at a time.
//
// Protocol: one JSON object per line on stdin ({"id": 1, "cmd": "goto", ...}); one JSON object per line on stdout
// ({"id": 1, "ok": true, ...} or {"id": 1, "ok": false, "error": "..."}). Commands: start, goto, observe, act,
// checks, render, close. Nothing else is written to stdout.
//
// Safety: only public http(s) addresses are visited. Every request the page makes is checked after DNS resolution
// and refused if it points at a private, loopback, link-local or reserved address; the address a response actually
// came from is checked again. robots.txt is honoured for the audit's user agent, and navigations are spaced out.

import { createInterface } from 'node:readline';
import { lookup } from 'node:dns/promises';
import { isIP } from 'node:net';
import { createRequire } from 'node:module';
import { chromium } from 'playwright';

const require = createRequire(import.meta.url);
const AXE_PATH = require.resolve('axe-core/axe.min.js');

const USER_AGENT_TOKEN = 'BuildPusherAudit';
const DESKTOP = { width: 1280, height: 800 };
const MOBILE = { width: 390, height: 844 };
const MIN_NAVIGATION_GAP_MS = 500;
const MAX_ELEMENTS = 60;

let browser = null;
let context = null;
let page = null;
let userAgent = `Mozilla/5.0 (compatible; ${USER_AGENT_TOKEN}/1.0)`;
let lastNavigationAt = 0;
let blockedUnsafe = null;
const robotsCache = new Map();
const addressCache = new Map();

// ---------------------------------------------------------------------------------------------------------------
// Address safety

/** Is an IPv4 or IPv6 address one the public internet can't reach (private, loopback, link-local, reserved)? */
function isPrivateAddress(address) {
    if (isIP(address) === 4) {
        const [a, b, c] = address.split('.').map(Number);
        return a === 0 || a === 10 || a === 127 || a >= 224 || (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254)
            || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 192 && b === 0 && (c === 0 || c === 2))
            || (a === 198 && (b === 18 || b === 19)) || (a === 198 && b === 51 && c === 100) || (a === 203 && b === 0 && c === 113);
    }
    const lower = address.toLowerCase();
    if (lower.startsWith('::ffff:')) {
        return isPrivateAddress(lower.slice(7));
    }
    return lower === '::' || lower === '::1' || lower.startsWith('fc') || lower.startsWith('fd') || lower.startsWith('fe8')
        || lower.startsWith('fe9') || lower.startsWith('fea') || lower.startsWith('feb') || lower.startsWith('ff');
}

/** Does a URL point only at public addresses? Answers are cached per host for the session. */
async function isPublicUrl(url) {
    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        return false;
    }
    if (parsed.protocol === 'data:' || parsed.protocol === 'blob:' || parsed.protocol === 'about:') {
        return true;
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        return false;
    }
    if (parsed.username || parsed.password) {
        return false;
    }
    const host = parsed.hostname.replace(/^\[|\]$/g, '');
    if (addressCache.has(host)) {
        return addressCache.get(host);
    }
    let safe;
    if (isIP(host)) {
        safe = !isPrivateAddress(host);
    } else if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.internal') || host.endsWith('.local') || !host.includes('.')) {
        safe = false;
    } else {
        try {
            const addresses = await lookup(host, { all: true, verbatim: true });
            safe = addresses.length > 0 && addresses.every((entry) => !isPrivateAddress(entry.address));
        } catch {
            safe = false;
        }
    }
    addressCache.set(host, safe);
    return safe;
}

// ---------------------------------------------------------------------------------------------------------------
// robots.txt

/** Fetch and parse an origin's robots.txt into the Disallow/Allow rules that apply to the audit. */
async function robotsRules(origin) {
    if (robotsCache.has(origin)) {
        return robotsCache.get(origin);
    }
    let rules = [];
    try {
        if (await isPublicUrl(`${origin}/robots.txt`)) {
            const response = await fetch(`${origin}/robots.txt`, { headers: { 'user-agent': userAgent }, redirect: 'manual', signal: AbortSignal.timeout(5000) });
            if (response.ok) {
                rules = parseRobots((await response.text()).slice(0, 200_000));
            }
        }
    } catch {
        rules = [];
    }
    robotsCache.set(origin, rules);
    return rules;
}

/** Keep the rules of the group for our user agent, or of the `*` group when there isn't one. */
function parseRobots(text) {
    const groups = [];
    let current = null;
    for (const raw of text.split(/\r?\n/)) {
        const line = raw.replace(/#.*$/, '').trim();
        const match = line.match(/^([A-Za-z-]+)\s*:\s*(.*)$/);
        if (!match) {
            continue;
        }
        const field = match[1].toLowerCase();
        const value = match[2].trim();
        if (field === 'user-agent') {
            if (!current || current.rules.length > 0) {
                current = { agents: [], rules: [] };
                groups.push(current);
            }
            current.agents.push(value.toLowerCase());
        } else if (current && (field === 'disallow' || field === 'allow')) {
            current.rules.push({ allow: field === 'allow', path: value });
        }
    }
    const ours = groups.find((group) => group.agents.some((agent) => agent !== '*' && USER_AGENT_TOKEN.toLowerCase().includes(agent)));
    return (ours ?? groups.find((group) => group.agents.includes('*')))?.rules ?? [];
}

/** May the audit visit this URL? The longest matching rule wins, and Allow wins ties. */
async function robotsAllows(url) {
    const parsed = new URL(url);
    const path = parsed.pathname + parsed.search;
    let best = null;
    for (const rule of await robotsRules(parsed.origin)) {
        if (rule.path === '') {
            continue;
        }
        const pattern = new RegExp('^' + rule.path.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\\\$$/, '$'));
        if (pattern.test(path) && (!best || rule.path.length > best.path.length || (rule.path.length === best.path.length && rule.allow))) {
            best = rule;
        }
    }
    return !best || best.allow;
}

// ---------------------------------------------------------------------------------------------------------------
// Browser

/** Start Chromium with a fresh context that checks every request it makes. */
async function start({ userAgent: agent, mobile = false }) {
    if (agent) {
        userAgent = agent;
    }
    browser ??= await chromium.launch({ args: ['--disable-dev-shm-usage', '--no-first-run'] });
    await context?.close();
    context = await browser.newContext({
        viewport: mobile ? MOBILE : DESKTOP,
        isMobile: mobile,
        hasTouch: mobile,
        userAgent,
        locale: 'en-GB',
        serviceWorkers: 'block',
        acceptDownloads: false,
        javaScriptEnabled: true,
    });
    await guard(context);
    page = await context.newPage();
    return {};
}

/** Refuse requests to non-public addresses, and remember when a response came from one anyway. */
async function guard(target) {
    await target.route('**/*', async (route) => {
        if (await isPublicUrl(route.request().url())) {
            await route.continue();
        } else {
            await route.abort('blockedbyclient');
        }
    });
    target.on('response', async (response) => {
        try {
            const address = (await response.serverAddr())?.ipAddress;
            if (address && isPrivateAddress(address)) {
                blockedUnsafe = response.url();
            }
        } catch {
            // The response may be gone already; nothing to check.
        }
    });
}

/** Wait so navigations are at least MIN_NAVIGATION_GAP_MS apart. */
async function paceNavigation() {
    const wait = lastNavigationAt + MIN_NAVIGATION_GAP_MS - Date.now();
    if (wait > 0) {
        await new Promise((resolve) => setTimeout(resolve, wait));
    }
    lastNavigationAt = Date.now();
}

/** Throw when a response came from a private address, ending the run. */
function assertSafe() {
    if (blockedUnsafe) {
        throw new Error(`Stopped: ${blockedUnsafe} answered from a private address.`);
    }
}

/** Open a URL, if it's public and robots.txt allows it. */
async function goto({ url }) {
    if (!(await isPublicUrl(url))) {
        throw new Error('Only public http(s) addresses can be audited.');
    }
    if (!(await robotsAllows(url))) {
        return { blocked: 'robots', url };
    }
    await paceNavigation();
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    await settle();
    assertSafe();
    return { url: page.url(), status: response?.status() ?? null, title: await page.title() };
}

/** Give the page a moment to finish loading and animating, without waiting forever on pages that never go idle. */
async function settle() {
    await page.waitForLoadState('domcontentloaded', { timeout: 20_000 }).catch(() => {});
    await page.waitForLoadState('load', { timeout: 10_000 }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 3_000 }).catch(() => {});
    await page.waitForTimeout(300);
}

/** Take a screenshot and list the visible interactive elements, retrying once or twice if a navigation interrupts. */
async function observe(command) {
    for (let attempt = 1; ; attempt++) {
        try {
            return await observeOnce(command);
        } catch (error) {
            if (attempt >= 3 || !/context was destroyed|navigat/i.test(String(error?.message))) {
                throw error;
            }
            await settle();
        }
    }
}

/** Take a screenshot and list the visible interactive elements, numbered so Claude can refer to them. */
async function observeOnce({ screenshot }) {
    assertSafe();
    const elements = await page.evaluate(({ max }) => {
        const selector = 'a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [role=link], [role=tab], [role=menuitem], [role=checkbox], [contenteditable=true]';
        const found = [];
        document.querySelectorAll('[data-bp-audit-n]').forEach((element) => element.removeAttribute('data-bp-audit-n'));
        for (const element of document.querySelectorAll(selector)) {
            const rect = element.getBoundingClientRect();
            const style = getComputedStyle(element);
            const visible = rect.width > 2 && rect.height > 2 && rect.bottom > 0 && rect.right > 0 && rect.top < innerHeight && rect.left < innerWidth
                && style.visibility !== 'hidden' && style.display !== 'none' && Number(style.opacity) > 0.05;
            if (!visible) {
                continue;
            }
            const text = (element.getAttribute('aria-label') || element.innerText || element.getAttribute('placeholder') || element.getAttribute('title')
                || element.getAttribute('alt') || element.querySelector('img[alt], svg[aria-label]')?.getAttribute('alt') || element.querySelector('svg[aria-label]')?.getAttribute('aria-label') || element.getAttribute('name') || element.getAttribute('value') || '').replace(/\s+/g, ' ').trim().slice(0, 80);
            const n = found.length + 1;
            element.setAttribute('data-bp-audit-n', String(n));
            found.push({
                n,
                tag: element.tagName.toLowerCase(),
                role: element.getAttribute('role') || (element.tagName === 'A' ? 'link' : element.tagName === 'INPUT' ? `input:${element.getAttribute('type') || 'text'}` : element.tagName.toLowerCase()),
                label: text,
                href: element.tagName === 'A' ? element.getAttribute('href') : undefined,
                box: { x: Math.round(rect.left), y: Math.round(rect.top), width: Math.round(rect.width), height: Math.round(rect.height) },
            });
            if (found.length >= max) {
                break;
            }
        }
        return found;
    }, { max: MAX_ELEMENTS });
    const metrics = await page.evaluate(() => ({ scrollY: Math.round(scrollY), pageHeight: document.documentElement.scrollHeight, viewportHeight: innerHeight }));
    const text = (await page.evaluate(() => document.body?.innerText ?? '')).replace(/\s+\n/g, '\n').replace(/\n{3,}/g, '\n\n').slice(0, 4000);
    await page.screenshot({ path: screenshot, type: 'jpeg', quality: 70 });
    return { url: page.url(), title: await page.title(), elements, text, ...metrics };
}

/** Do what Claude asked: click, type, choose, scroll, go back or press Enter. */
async function act({ action }) {
    assertSafe();
    const target = action.n ? page.locator(`[data-bp-audit-n="${Number(action.n)}"]`).first() : null;
    const before = page.url();
    if (action.n && (await target.count()) === 0) {
        throw new Error(`There is no element ${action.n} on the page any more.`);
    }
    switch (action.type) {
        case 'click': {
            const href = await target.evaluate((element) => (element.tagName === 'A' ? element.href : null));
            if (href && !(await isPublicUrl(href))) {
                throw new Error('That link leads to an address that can’t be visited.');
            }
            if (href && /^https?:/.test(href) && !(await robotsAllows(href))) {
                return { blocked: 'robots', url: href };
            }
            await paceNavigation();
            await target.click({ timeout: 5_000, noWaitAfter: true });
            await page.waitForURL((current) => current.href !== before, { timeout: 3_000 }).catch(() => {});
            break;
        }
        case 'type':
            await target.fill(String(action.text ?? '').slice(0, 200), { timeout: 5_000 });
            break;
        case 'select':
            await target.selectOption({ label: String(action.text ?? '') }, { timeout: 5_000 }).catch(() => target.selectOption(String(action.text ?? ''), { timeout: 5_000 }));
            break;
        case 'press_enter':
            await paceNavigation();
            await (target ?? page.locator('body')).press('Enter', { timeout: 5_000, noWaitAfter: true });
            await page.waitForURL((current) => current.href !== before, { timeout: 3_000 }).catch(() => {});
            break;
        case 'scroll':
            await page.mouse.wheel(0, action.direction === 'up' ? -600 : 600);
            break;
        case 'back':
            await paceNavigation();
            await page.goBack({ timeout: 15_000 }).catch(() => {});
            break;
        default:
            throw new Error(`Unknown action ${action.type}.`);
    }
    await settle();
    assertSafe();
    return { url: page.url(), navigated: page.url() !== before };
}

/** Measure a page: speed, accessibility, search basics and how it works on a phone. */
async function checks({ url }) {
    if (!(await isPublicUrl(url))) {
        throw new Error('Only public http(s) addresses can be audited.');
    }
    if (!(await robotsAllows(url))) {
        return { blocked: 'robots' };
    }
    const desktop = await browser.newContext({ viewport: DESKTOP, userAgent, serviceWorkers: 'block' });
    await guard(desktop);
    const measured = await desktop.newPage();
    await measured.addInitScript(() => {
        window.__bpAudit = { lcp: 0, cls: 0 };
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                window.__bpAudit.lcp = entry.startTime;
            }
        }).observe({ type: 'largest-contentful-paint', buffered: true });
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (!entry.hadRecentInput) {
                    window.__bpAudit.cls += entry.value;
                }
            }
        }).observe({ type: 'layout-shift', buffered: true });
    });
    await paceNavigation();
    const response = await measured.goto(url, { waitUntil: 'load', timeout: 30_000 });
    await measured.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => {});
    assertSafe();

    const performance = await measured.evaluate(() => {
        const navigation = performance.getEntriesByType('navigation')[0];
        const resources = performance.getEntriesByType('resource');
        return {
            ttfbMs: Math.round(navigation?.responseStart ?? 0),
            domContentLoadedMs: Math.round(navigation?.domContentLoadedEventEnd ?? 0),
            loadMs: Math.round(navigation?.loadEventEnd ?? 0),
            lcpMs: Math.round(window.__bpAudit.lcp),
            cls: Math.round(window.__bpAudit.cls * 1000) / 1000,
            requests: resources.length + 1,
            transferBytes: Math.round(resources.reduce((sum, entry) => sum + (entry.transferSize || 0), navigation?.transferSize ?? 0)),
        };
    });

    const seo = await measured.evaluate(() => {
        const meta = (name) => document.querySelector(`meta[name="${name}"], meta[property="${name}"]`)?.getAttribute('content') ?? null;
        return {
            title: document.title,
            description: meta('description'),
            h1Count: document.querySelectorAll('h1').length,
            canonical: document.querySelector('link[rel=canonical]')?.getAttribute('href') ?? null,
            lang: document.documentElement.getAttribute('lang'),
            viewport: meta('viewport'),
            robots: meta('robots'),
            ogImage: meta('og:image'),
            structuredData: document.querySelectorAll('script[type="application/ld+json"]').length,
            imagesWithoutAlt: [...document.images].filter((image) => !image.hasAttribute('alt')).length,
            images: document.images.length,
        };
    });

    await measured.addScriptTag({ path: AXE_PATH });
    const accessibility = await measured.evaluate(async () => {
        const result = await window.axe.run(document, { resultTypes: ['violations'], runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } });
        return result.violations.map((violation) => ({ id: violation.id, impact: violation.impact, help: violation.help, nodes: violation.nodes.length }));
    });
    await desktop.close();

    const phone = await browser.newContext({ viewport: MOBILE, isMobile: true, hasTouch: true, userAgent, serviceWorkers: 'block' });
    await guard(phone);
    const small = await phone.newPage();
    await paceNavigation();
    await small.goto(url, { waitUntil: 'load', timeout: 30_000 });
    await small.waitForTimeout(500);
    assertSafe();
    const mobile = await small.evaluate(() => {
        const targets = [...document.querySelectorAll('a[href], button, input, select, textarea, [role=button]')].filter((element) => {
            const rect = element.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0;
        });
        return {
            horizontalOverflow: document.documentElement.scrollWidth > innerWidth + 2,
            smallTapTargets: targets.filter((element) => {
                const rect = element.getBoundingClientRect();
                return rect.width < 24 || rect.height < 24;
            }).length,
            tapTargets: targets.length,
            smallText: [...document.querySelectorAll('p, li, a, span, td')].filter((element) => element.childElementCount === 0 && element.innerText?.trim()
                && parseFloat(getComputedStyle(element).fontSize) < 12).length,
        };
    });
    await phone.close();

    return { url, status: response?.status() ?? null, performance, seo, accessibility, mobile };
}

/** Render a mock-up (Claude's improved HTML for one section) with no network and no scripts, and screenshot it. */
async function render({ html, screenshot, mobile = false }) {
    const sandbox = await browser.newContext({ viewport: mobile ? MOBILE : DESKTOP, javaScriptEnabled: false, offline: true });
    await sandbox.route('**/*', (route) => (route.request().url().startsWith('data:') ? route.continue() : route.abort('blockedbyclient')));
    const mock = await sandbox.newPage();
    await mock.setContent(String(html).slice(0, 100_000), { waitUntil: 'load', timeout: 10_000 });
    await mock.screenshot({ path: screenshot, type: 'png', fullPage: true, clip: undefined });
    await sandbox.close();
    return {};
}

/** Close the browser. */
async function close() {
    await browser?.close();
    browser = null;
    return {};
}

// ---------------------------------------------------------------------------------------------------------------
// Command loop

const commands = { start, goto, observe, act, checks, render, close };

const lines = createInterface({ input: process.stdin });
for await (const line of lines) {
    if (!line.trim()) {
        continue;
    }
    let message;
    try {
        message = JSON.parse(line);
    } catch {
        process.stdout.write(JSON.stringify({ id: null, ok: false, error: 'Unreadable command.' }) + '\n');
        continue;
    }
    const handler = commands[message.cmd];
    try {
        if (!handler) {
            throw new Error(`Unknown command ${message.cmd}.`);
        }
        const result = await handler(message);
        process.stdout.write(JSON.stringify({ id: message.id ?? null, ok: true, ...result }) + '\n');
    } catch (error) {
        process.stdout.write(JSON.stringify({ id: message.id ?? null, ok: false, error: String(error?.message ?? error).slice(0, 500) }) + '\n');
    }
    if (message.cmd === 'close') {
        break;
    }
}
await close();
process.exit(0);
