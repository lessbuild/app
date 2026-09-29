# SDKs and integrations

- `php/` — `buildpusher/sdk` for Composer. Tested in this repository against the real API routes
  (`tests/Feature/Api/PhpSdkTest.php`).
- `js/` — `@buildpusher/sdk` for npm, with TypeScript types. Run `npm test` in `sdk/js`.
- `../integrations/github-action/` — the GitHub Action that deploys with the CLI.

They're ready to publish (Packagist, npm, a public action repository), but aren't published yet.
