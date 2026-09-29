# BuildPusher PHP SDK

A small, dependency-free client for the [BuildPusher API](https://buildpusher.com/docs/api).

```bash
composer require buildpusher/sdk
```

```php
use BuildPusher\Sdk\Client;

$client = new Client(getenv('BUILDPUSHER_TOKEN'));

$deploy = $client->deploy($environmentId, 'v1.4.0');   // branch, tag or commit; omit for the repository's branch
$done = $client->waitForDeployment($deploy['id']);
echo $done['status'];                                    // succeeded, failed, canceled or rejected

echo $client->log($deploy['id'])['log'];
$client->rollback($deploy['id']);
$client->replaceVariables($environmentId, "APP_ENV=production\n");
```

Create a token under **Account → API tokens** with the Deploy scopes. Errors throw `BuildPusher\Sdk\ApiException`
with the HTTP status.
