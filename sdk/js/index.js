/**
 * A small client for the BuildPusher API (v1), using fetch (Node 18+, Deno, Bun and browsers).
 *
 *   const client = new BuildPusher({ token: process.env.BUILDPUSHER_TOKEN });
 *   const deploy = await client.deploy(environmentId, { ref: 'v1.4.0' });
 *   const done = await client.waitForDeployment(deploy.id);
 */

export const FINISHED = ['succeeded', 'failed', 'canceled', 'rejected'];

/** The API answered with an error status. */
export class BuildPusherError extends Error {
    /**
     * @param {string} message
     * @param {number} status
     * @param {object} body
     */
    constructor(message, status, body = {}) {
        super(message);
        this.name = 'BuildPusherError';
        this.status = status;
        this.body = body;
    }
}

export class BuildPusher {
    /**
     * @param {{ token: string, baseUrl?: string, fetch?: typeof fetch }} options
     */
    constructor({ token, baseUrl = 'https://buildpusher.com', fetch: fetchImpl = globalThis.fetch } = {}) {
        if (!token) {
            throw new Error('A BuildPusher API token is required.');
        }
        this.token = token;
        this.baseUrl = baseUrl.replace(/\/+$/, '');
        this.fetch = fetchImpl;
    }

    /** Who the token belongs to, and its account. */
    me() {
        return this.#data('GET', '/me');
    }

    /** The projects the token can deploy, with their environments. */
    projects() {
        return this.#data('GET', '/projects');
    }

    /** @param {string} projectId */
    project(projectId) {
        return this.#data('GET', `/projects/${encodeURIComponent(projectId)}`);
    }

    /** The Analytics sites the token can read (needs the analytics:read scope). */
    analyticsSites() {
        return this.#data('GET', '/analytics/sites');
    }

    /**
     * A site's Analytics report as numbers.
     * @param {number} siteId
     * @param {{ days?: 1|7|30|90|365, path?: string, source?: string, campaign?: string, device?: string, country?: string }} [options]
     */
    analyticsReport(siteId, { days = 30, ...filters } = {}) {
        const query = new URLSearchParams({ days: String(days), ...filters });
        return this.#data('GET', `/analytics/sites/${Number(siteId)}/report?${query}`);
    }

    /**
     * A site's daily totals as flat rows, one per day and value of a breakdown (path, source, channel, campaign,
     * country, city, device, browser, operating_system or screen_size; all for the whole site), fetching every page.
     * @param {number} siteId
     * @param {{ from: string, to: string, dimension?: string }} options dates as YYYY-MM-DD
     */
    async analyticsRows(siteId, { from, to, dimension = 'all' }) {
        const rows = [];
        for (let page = 1; ; page++) {
            const batch = await this.#data('GET', `/analytics/sites/${Number(siteId)}/rows?${new URLSearchParams({ from, to, dimension, page: String(page) })}`);
            rows.push(...batch);
            if (batch.length < 5000) return rows;
        }
    }

    /**
     * Send pageviews and custom events from your server (needs the analytics:write scope). Pass each visitor's IP
     * address and User-Agent so they're counted like browser visits.
     * @param {number} siteId
     * @param {Array<{ type: 'pageview'|'event', path: string, name?: string, properties?: Record<string, unknown>, ip?: string, user_agent?: string, referrer?: string, utm_source?: string, utm_medium?: string, utm_campaign?: string }>} events
     */
    analyticsEvents(siteId, events) {
        return this.#data('POST', `/analytics/sites/${Number(siteId)}/events`, { events });
    }

    /** Recent deploys, newest first. @param {{ limit?: number }} [options] */
    deployments({ limit = 25 } = {}) {
        return this.#data('GET', `/deployments?limit=${Math.max(1, Math.min(100, limit))}`);
    }

    /** @param {number} deploymentId */
    deployment(deploymentId) {
        return this.#data('GET', `/deployments/${Number(deploymentId)}`);
    }

    /** A deploy's log (its tail) and status. @param {number} deploymentId */
    log(deploymentId) {
        return this.#data('GET', `/deployments/${Number(deploymentId)}/log`);
    }

    /**
     * Deploy an environment, optionally a branch, tag or commit instead of its repository's branch.
     * @param {string} environmentId
     * @param {{ ref?: string }} [options]
     */
    deploy(environmentId, { ref } = {}) {
        return this.#data('POST', `/environments/${encodeURIComponent(environmentId)}/deploy`, ref ? { ref } : undefined);
    }

    /** Roll back to the release a deploy shipped. @param {number} deploymentId */
    async rollback(deploymentId) {
        const data = await this.#data('POST', `/deployments/${Number(deploymentId)}/rollback`);
        return data.deployment ?? data;
    }

    /**
     * Replace an environment's variables with .env text: status "applied", or "pending_approval" when a second
     * person must approve.
     * @param {string} environmentId
     * @param {string} dotenv
     */
    replaceVariables(environmentId, dotenv) {
        return this.#data('PUT', `/environments/${encodeURIComponent(environmentId)}/variables`, { variables: dotenv });
    }

    /**
     * Poll a deploy until it finishes.
     * @param {number} deploymentId
     * @param {{ timeoutMs?: number, intervalMs?: number }} [options]
     */
    async waitForDeployment(deploymentId, { timeoutMs = 30 * 60 * 1000, intervalMs = 5000 } = {}) {
        const deadline = Date.now() + timeoutMs;
        for (;;) {
            const deployment = await this.deployment(deploymentId);
            if (FINISHED.includes(deployment.status)) {
                return deployment;
            }
            if (Date.now() >= deadline) {
                throw new BuildPusherError(`Deploy #${deploymentId} didn't finish in time.`, 408);
            }
            await new Promise((resolve) => setTimeout(resolve, intervalMs));
        }
    }

    async #data(method, path, body) {
        const response = await this.fetch(`${this.baseUrl}/api/v1${path}`, {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${this.token}`,
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        let json = {};
        try {
            json = await response.json();
        } catch {
            json = {};
        }
        if (!response.ok) {
            throw new BuildPusherError(json.message ?? `The API answered HTTP ${response.status}.`, response.status, json);
        }
        return json.data ?? {};
    }
}

export default BuildPusher;
