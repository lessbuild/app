# BuildPusher JavaScript SDK

A small client for the [BuildPusher API](https://buildpusher.com/docs/api), built on `fetch` (Node 18+, Deno, Bun,
browsers) with TypeScript types.

```bash
npm install @buildpusher/sdk
```

```js
import { BuildPusher } from '@buildpusher/sdk';

const client = new BuildPusher({ token: process.env.BUILDPUSHER_TOKEN });

const deploy = await client.deploy(environmentId, { ref: 'v1.4.0' }); // or omit ref for the repository's branch
const done = await client.waitForDeployment(deploy.id);
console.log(done.status); // succeeded, failed, canceled or rejected

console.log((await client.log(deploy.id)).log);
await client.rollback(deploy.id);
```

Create a token under **Account → API tokens** with the Deploy scopes. Errors reject with a `BuildPusherError` whose
`status` is the HTTP status.
