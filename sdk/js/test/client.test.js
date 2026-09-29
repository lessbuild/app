import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BuildPusher, BuildPusherError } from '../index.js';

function fakeFetch(routes) {
    const calls = [];
    const fetch = async (url, init) => {
        calls.push({ url, init });
        const key = `${init.method} ${new URL(url).pathname}`;
        const handler = routes[key];
        const [status, body] = handler ? handler(init) : [404, { message: 'Not found.' }];
        return { ok: status < 400, status, json: async () => body };
    };
    return { fetch, calls };
}

test('deploys a ref and waits for it', async () => {
    let polls = 0;
    const { fetch, calls } = fakeFetch({
        'POST /api/v1/environments/env_1/deploy': (init) => [202, { data: { id: 7, status: 'queued', ref: JSON.parse(init.body).ref } }],
        'GET /api/v1/deployments/7': () => [200, { data: { id: 7, status: ++polls < 2 ? 'running' : 'succeeded' } }],
    });
    const client = new BuildPusher({ token: 'bp_test', baseUrl: 'https://bp.test/', fetch });

    const deploy = await client.deploy('env_1', { ref: 'v1.4.0' });
    assert.equal(deploy.ref, 'v1.4.0');
    const done = await client.waitForDeployment(deploy.id, { intervalMs: 1 });
    assert.equal(done.status, 'succeeded');
    assert.equal(calls[0].url, 'https://bp.test/api/v1/environments/env_1/deploy');
    assert.equal(calls[0].init.headers.Authorization, 'Bearer bp_test');
});

test('rollback returns the new deploy and errors carry the status', async () => {
    const { fetch } = fakeFetch({
        'POST /api/v1/deployments/7/rollback': () => [202, { data: { status: 'queued', deployment: { id: 8, status: 'queued' } } }],
    });
    const client = new BuildPusher({ token: 'bp_test', fetch });

    assert.equal((await client.rollback(7)).id, 8);
    await assert.rejects(client.deployment(99), (error) => error instanceof BuildPusherError && error.status === 404);
    assert.throws(() => new BuildPusher({}), /token is required/);
});
