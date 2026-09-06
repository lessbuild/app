# BuildPusher CLI and MCP

The dependency-free CLI lives at `bin/buildpusher`. Install it somewhere on your `PATH`, then authenticate:

```bash
buildpusher login YOUR_API_TOKEN https://buildpusher.com
buildpusher projects
buildpusher deploy 42
buildpusher logs 101
buildpusher rollback 100
buildpusher env-push 42 .env.production
```

Credentials are stored with mode `0600` under `$XDG_CONFIG_HOME/buildpusher/config`. Environment variables `BUILDPUSHER_URL` and `BUILDPUSHER_TOKEN` take precedence.

For an MCP client, configure the stdio server with the same environment variables:

```json
{
  "mcpServers": {
    "buildpusher": {
      "command": "/path/to/deployer/bin/buildpusher-mcp",
      "env": {"BUILDPUSHER_URL": "https://buildpusher.com", "BUILDPUSHER_TOKEN": "..."}
    }
  }
}
```

Use a token whose scopes match the desired tools. Read-only tokens cannot deploy, roll back, or scale.
