#!/usr/bin/env php
<?php

/*
 * BuildPusher CLI: deploy, follow logs and roll back from the terminal, over the BuildPusher API.
 *
 * One file, no dependencies beyond PHP 8.1 with OpenSSL. Install:
 *   curl -fsSL https://buildpusher.com/cli/install.sh | sh
 *
 * The token comes from `buildpusher login` (saved to ~/.config/buildpusher/config.json) or BUILDPUSHER_TOKEN;
 * BUILDPUSHER_URL points it at another installation.
 */

declare(strict_types=1);

const BUILDPUSHER_CLI_VERSION = '1.0.0';
const FINISHED = ['succeeded', 'failed', 'canceled', 'rejected'];

exit(main(array_slice($argv, 1)));

/**
 * Run a command and return the exit code.
 *
 * @param  list<string>  $args
 * @return int
 */
function main(array $args): int
{
    [$positional, $options] = parseArguments($args);
    $command = array_shift($positional) ?? 'help';

    try {
        return match ($command) {
            'login' => login($options),
            'logout' => logout(),
            'whoami' => whoami(),
            'projects' => projects(),
            'deploy' => deploy($positional, $options),
            'status' => status($positional, $options),
            'logs' => logs($positional, $options),
            'rollback' => rollback($positional, $options),
            'version', '--version', '-v' => say('buildpusher '.BUILDPUSHER_CLI_VERSION),
            'help', '--help', '-h' => help(),
            default => fail("Unknown command \"{$command}\". Run `buildpusher help`."),
        };
    } catch (CliError $error) {
        return fail($error->getMessage());
    }
}

/**
 * Split arguments into positional ones and --options (`--name=value`, `--name value` or a bare `--flag`).
 *
 * @param  list<string>  $args
 * @return array{0: list<string>, 1: array<string, string|true>}
 */
function parseArguments(array $args): array
{
    $positional = [];
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if (! str_starts_with($arg, '--') || $arg === '--') {
            $positional[] = $arg;

            continue;
        }
        $name = substr($arg, 2);
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
            $options[$name] = $value;
        } elseif (in_array($name, ['url', 'environment', 'env', 'token'], true) && isset($args[$i + 1])) {
            $options[$name] = $args[++$i];
        } else {
            $options[$name] = true;
        }
    }

    return [$positional, $options];
}

/**
 * Show how to use the CLI.
 *
 * @return int
 */
function help(): int
{
    return say(<<<'HELP'
BuildPusher CLI

Usage:
  buildpusher login [--url=https://buildpusher.com]   Save an API token (Account → API tokens, with Deploy scopes)
  buildpusher whoami                                  Show who the token belongs to
  buildpusher projects                                List projects and their environments
  buildpusher deploy <project> [environment] [--wait] Deploy an environment (production by default)
  buildpusher status [project] [environment]          Show the latest deploys
  buildpusher logs <deploy-id> [--follow]             Print a deploy's log, following it until it finishes
  buildpusher rollback <deploy-id> [--wait]           Roll back to the release a deploy shipped
  buildpusher logout                                  Forget the saved token

<project> is a project's slug, name or ID. A .buildpusher.json with {"project": "…", "environment": "…"} in the
current directory sets the defaults. BUILDPUSHER_TOKEN and BUILDPUSHER_URL override the saved login.
HELP);
}

/**
 * Ask for a token, check it, and save it.
 *
 * @param  array<string, string|true>  $options
 * @return int
 */
function login(array $options): int
{
    $url = rtrim(is_string($options['url'] ?? null) ? $options['url'] : (config()['url'] ?? 'https://buildpusher.com'), '/');
    $token = is_string($options['token'] ?? null) ? $options['token'] : prompt("Paste an API token from {$url}/account/api-tokens: ", true);
    if ($token === '') {
        throw new CliError('No token given.');
    }
    $me = request('GET', '/me', null, ['url' => $url, 'token' => $token])['data'];
    saveConfig(['url' => $url, 'token' => $token]);

    return say("Logged in as {$me['name']} ({$me['email']}) in {$me['organization']['name']}.");
}

/**
 * Remove the saved token.
 *
 * @return int
 */
function logout(): int
{
    $path = configPath();
    if (is_file($path)) {
        unlink($path);
    }

    return say('Logged out.');
}

/**
 * Show whose token is in use.
 *
 * @return int
 */
function whoami(): int
{
    $me = request('GET', '/me')['data'];

    return say("{$me['name']} <{$me['email']}> · {$me['organization']['name']} ({$me['organization']['plan']} plan)");
}

/**
 * List the projects and environments the token can deploy.
 *
 * @return int
 */
function projects(): int
{
    foreach (request('GET', '/projects')['data'] as $project) {
        say(bold($project['name'])." ({$project['slug']})");
        foreach ($project['environments'] as $environment) {
            say("  {$environment['slug']}  ".dim($environment['id']).($environment['state'] === 'hibernated' ? ' · hibernated' : ''));
        }
    }

    return 0;
}

/**
 * Deploy an environment, optionally following the log until it finishes.
 *
 * @param  list<string>  $args
 * @param  array<string, string|true>  $options
 * @return int
 */
function deploy(array $args, array $options): int
{
    [$project, $environment] = resolveEnvironment($args, $options);
    $build = request('POST', "/environments/{$environment['id']}/deploy")['data'];
    say("Deploy #{$build['id']} of {$project['name']} to {$environment['name']}: {$build['status']}");
    say(dim(baseUrl()."/projects/{$project['id']}/deploy/builds/{$build['id']}"));

    return isset($options['wait']) ? follow((int) $build['id']) : 0;
}

/**
 * Show the latest deploys, for one project or environment when given.
 *
 * @param  list<string>  $args
 * @param  array<string, string|true>  $options
 * @return int
 */
function status(array $args, array $options): int
{
    $environmentIds = null;
    $names = [];
    foreach (request('GET', '/projects')['data'] as $project) {
        foreach ($project['environments'] as $environment) {
            $names[$environment['id']] = "{$project['name']} / {$environment['name']}";
        }
    }
    if ($args !== [] || defaults()['project'] !== null) {
        [$project, $environment] = resolveEnvironment($args, $options);
        $named = isset($args[1]) || isset($options['environment']) || isset($options['env']) || defaults()['environment'] !== null;
        $environmentIds = $named ? [$environment['id']] : array_column($project['environments'], 'id');
    }
    $builds = array_filter(request('GET', '/deployments?limit=50')['data'], fn (array $build): bool => $environmentIds === null || in_array($build['environment_id'], $environmentIds, true));
    if ($builds === []) {
        return say('No deploys yet.');
    }
    foreach (array_slice($builds, 0, 15) as $build) {
        say(sprintf('#%-6s %-20s %-10s %-9s %s  %s', $build['id'], statusLabel($build['status']), substr((string) $build['revision'], 0, 8) ?: '—', $build['trigger'], $names[$build['environment_id']] ?? '', dim((string) $build['created_at'])));
    }

    return 0;
}

/**
 * Print a deploy's log, and with --follow keep printing until it finishes.
 *
 * @param  list<string>  $args
 * @param  array<string, string|true>  $options
 * @return int
 */
function logs(array $args, array $options): int
{
    $id = deployId($args);
    if (isset($options['follow']) || isset($options['f'])) {
        return follow($id);
    }
    $log = request('GET', "/deployments/{$id}/log")['data'];
    fwrite(STDOUT, $log['log'] === '' ? "(no log yet)\n" : rtrim($log['log'])."\n");

    return 0;
}

/**
 * Roll back to the release a deploy shipped.
 *
 * @param  list<string>  $args
 * @param  array<string, string|true>  $options
 * @return int
 */
function rollback(array $args, array $options): int
{
    $id = deployId($args);
    $build = request('POST', "/deployments/{$id}/rollback")['data']['deployment'];
    say("Rolling back with deploy #{$build['id']}: {$build['status']}");

    return isset($options['wait']) ? follow((int) $build['id']) : 0;
}

/**
 * Print a deploy's log as it grows until the deploy finishes. Exits 0 when it went live, 1 otherwise.
 *
 * @param  int  $id
 * @return int
 */
function follow(int $id): int
{
    $printed = 0;
    while (true) {
        $log = request('GET', "/deployments/{$id}/log")['data'];
        $text = (string) $log['log'];
        // The server keeps the log's tail, so when it has scrolled past what we printed, print the new tail whole.
        $new = strlen($text) >= $printed ? substr($text, $printed) : $text;
        if ($new !== '') {
            fwrite(STDOUT, $new);
            $printed = strlen($text);
        }
        if (in_array($log['status'], FINISHED, true)) {
            if ($text !== '' && ! str_ends_with($text, "\n")) {
                fwrite(STDOUT, "\n");
            }
            say("Deploy #{$id}: ".statusLabel($log['status']));

            return $log['status'] === 'succeeded' ? 0 : 1;
        }
        sleep(3);
    }
}

/**
 * Find the project and environment the arguments (or .buildpusher.json) name. The environment defaults to
 * production, or the project's first environment.
 *
 * @param  list<string>  $args
 * @param  array<string, string|true>  $options
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function resolveEnvironment(array $args, array $options): array
{
    $defaults = defaults();
    $wanted = $args[0] ?? $defaults['project'] ?? throw new CliError('Name a project: buildpusher deploy <project> [environment]');
    $wantedEnvironment = $args[1] ?? (is_string($options['environment'] ?? null) ? $options['environment'] : null)
        ?? (is_string($options['env'] ?? null) ? $options['env'] : null) ?? $defaults['environment'];
    $needle = strtolower($wanted);
    $matches = array_values(array_filter(request('GET', '/projects')['data'], fn (array $project): bool => in_array($needle, [strtolower($project['id']), strtolower($project['slug']), strtolower($project['name'])], true)));
    if (count($matches) !== 1) {
        throw new CliError(count($matches) === 0 ? "No project called \"{$wanted}\". Run `buildpusher projects`." : "More than one project is called \"{$wanted}\"; use its slug or ID.");
    }
    $project = $matches[0];
    $environments = $project['environments'];
    if ($environments === []) {
        throw new CliError("{$project['name']} has no environments.");
    }
    if ($wantedEnvironment === null) {
        $production = array_values(array_filter($environments, fn (array $environment): bool => $environment['slug'] === 'production'));

        return [$project, $production[0] ?? $environments[0]];
    }
    $needle = strtolower($wantedEnvironment);
    foreach ($environments as $environment) {
        if (in_array($needle, [strtolower($environment['id']), strtolower($environment['slug']), strtolower($environment['name'])], true)) {
            return [$project, $environment];
        }
    }

    throw new CliError("{$project['name']} has no environment called \"{$wantedEnvironment}\". It has: ".implode(', ', array_column($environments, 'slug')).'.');
}

/**
 * Read the deploy ID argument.
 *
 * @param  list<string>  $args
 * @return int
 */
function deployId(array $args): int
{
    $id = ltrim($args[0] ?? '', '#');
    if (! ctype_digit($id)) {
        throw new CliError('Give a deploy ID, e.g. buildpusher logs 1234 (see `buildpusher status`).');
    }

    return (int) $id;
}

/**
 * Call the API and return the decoded JSON, turning errors into readable messages.
 *
 * @param  string  $method
 * @param  string  $path  under /api/v1
 * @param  array<string, mixed>|null  $body
 * @param  array{url?: string, token?: string}  $auth  overrides the saved login
 * @return array<string, mixed>
 */
function request(string $method, string $path, ?array $body = null, array $auth = []): array
{
    $token = $auth['token'] ?? (getenv('BUILDPUSHER_TOKEN') ?: (config()['token'] ?? null));
    if (! is_string($token) || $token === '') {
        throw new CliError('Not logged in. Run `buildpusher login` or set BUILDPUSHER_TOKEN.');
    }
    $url = ($auth['url'] ?? baseUrl()).'/api/v1'.$path;
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", ['Accept: application/json', 'Content-Type: application/json', "Authorization: Bearer {$token}", 'User-Agent: buildpusher-cli/'.BUILDPUSHER_CLI_VERSION]),
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new CliError("Couldn't reach {$url}.");
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    $data = json_decode($response, true);
    if ($status >= 400 || ! is_array($data)) {
        $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? null) : null;
        throw new CliError(match (true) {
            $status === 401 => 'The token was refused. Run `buildpusher login` again.',
            $status === 403 => 'The token isn’t allowed to do that. It needs the Deploy scopes.',
            $status === 404 => 'Not found. Check the project, environment or deploy ID.',
            default => "The API answered {$status}".(is_string($message) ? ": {$message}" : '.'),
        });
    }

    return $data;
}

/**
 * Get the installation's address.
 *
 * @return string
 */
function baseUrl(): string
{
    return rtrim(getenv('BUILDPUSHER_URL') ?: (config()['url'] ?? 'https://buildpusher.com'), '/');
}

/**
 * Get the project and environment set in .buildpusher.json in the current directory.
 *
 * @return array{project: string|null, environment: string|null}
 */
function defaults(): array
{
    $file = getcwd().'/.buildpusher.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return [
        'project' => is_array($data) && is_string($data['project'] ?? null) ? $data['project'] : null,
        'environment' => is_array($data) && is_string($data['environment'] ?? null) ? $data['environment'] : null,
    ];
}

/**
 * Get where the login is saved.
 *
 * @return string
 */
function configPath(): string
{
    $base = getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') ?: (getenv('USERPROFILE') ?: '.')).'/.config';

    return $base.'/buildpusher/config.json';
}

/**
 * Read the saved login.
 *
 * @return array{url?: string, token?: string}
 */
function config(): array
{
    $path = configPath();
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    return is_array($data) ? $data : [];
}

/**
 * Save the login where only this user can read it.
 *
 * @param  array{url: string, token: string}  $config
 * @return void
 */
function saveConfig(array $config): void
{
    $path = configPath();
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
}

/**
 * Ask a question on the terminal, hiding the answer when it's secret.
 *
 * @param  string  $question
 * @param  bool  $secret
 * @return string
 */
function prompt(string $question, bool $secret = false): string
{
    fwrite(STDOUT, $question);
    $hidden = $secret && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($hidden) {
        shell_exec('stty -echo');
    }
    $answer = trim((string) fgets(STDIN));
    if ($hidden) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return $answer;
}

/**
 * Describe a deploy status for people.
 *
 * @param  string  $status
 * @return string
 */
function statusLabel(string $status): string
{
    return match ($status) {
        'succeeded' => color('Live', '32'),
        'failed' => color('Failed', '31'),
        'awaiting_approval' => color('Needs approval', '33'),
        'canceled', 'rejected' => 'Stopped',
        'queued' => 'Queued',
        default => color('Deploying', '36'),
    };
}

/**
 * Print a line and return success.
 *
 * @param  string  $line
 * @return int
 */
function say(string $line): int
{
    fwrite(STDOUT, $line."\n");

    return 0;
}

/**
 * Print an error and return failure.
 *
 * @param  string  $message
 * @return int
 */
function fail(string $message): int
{
    fwrite(STDERR, color('Error:', '31').' '.$message."\n");

    return 1;
}

/**
 * Colour text on terminals that show colour.
 *
 * @param  string  $text
 * @param  string  $code  an ANSI colour code
 * @return string
 */
function color(string $text, string $code): string
{
    return stream_isatty(STDOUT) && getenv('NO_COLOR') === false ? "\033[{$code}m{$text}\033[0m" : $text;
}

/**
 * Make text bold on terminals that show it.
 *
 * @param  string  $text
 * @return string
 */
function bold(string $text): string
{
    return color($text, '1');
}

/**
 * Dim text on terminals that show it.
 *
 * @param  string  $text
 * @return string
 */
function dim(string $text): string
{
    return color($text, '2');
}

/** An error to show the person, without a stack trace. */
final class CliError extends RuntimeException {}
