<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

/**
 * The shell scripts that install and remove the log agent: a Python program run by systemd that follows the journal
 * (warnings and worse) and the websites' Laravel logs, and sends them to Monitoring in batches, at most 600 lines a
 * minute (more are counted and reported as dropped).
 */
final class LogShipperScript
{
    /**
     * The agent itself.
     *
     * @var string
     */
    private const AGENT = <<<'PYTHON'
#!/usr/bin/env python3
# BuildPusher log agent: journal warnings and Laravel logs to Monitoring.
import glob, json, os, queue, re, socket, subprocess, threading, time, urllib.request, uuid

CONFIG = json.load(open('/etc/buildpusher/logs.json'))
HOST = socket.gethostname()
LINES = queue.Queue(maxsize=5000)
PRIORITY = {0: 'critical', 1: 'critical', 2: 'critical', 3: 'error', 4: 'warning'}
LARAVEL = re.compile(r'^\[(\d{4}-\d\d-\d\d[ T][\d:.+-]+)\] \w+\.(\w+): (.*)$')

def put(event):
    try:
        LINES.put_nowait(event)
    except queue.Full:
        pass

def event(severity, service, message, source, details=None):
    return {'id': str(uuid.uuid4()), 'type': 'log', 'severity': severity, 'service': service[:100], 'name': message[:255],
            'details': (details or message)[:10000], 'timestamp': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
            'attributes': {'host.name': HOST, 'log.source': source}}

def journal():
    while True:
        process = subprocess.Popen(['journalctl', '-f', '-n', '0', '-p', 'warning', '-o', 'json'], stdout=subprocess.PIPE, text=True)
        for line in process.stdout:
            try:
                entry = json.loads(line)
            except ValueError:
                continue
            message = entry.get('MESSAGE')
            if not isinstance(message, str) or not message.strip():
                continue
            unit = entry.get('_SYSTEMD_UNIT') or entry.get('SYSLOG_IDENTIFIER') or 'system'
            put(event(PRIORITY.get(int(entry.get('PRIORITY', 4)), 'warning'), unit.replace('.service', ''), message.strip(), 'journal'))
        time.sleep(5)

def files():
    positions = {}
    pending = {}
    first = True
    while True:
        for path in glob.glob('/var/www/*/shared/storage/logs/*.log'):
            try:
                stat = os.stat(path)
            except OSError:
                continue
            known = positions.get(path)
            if known is None or known[0] != stat.st_ino or known[1] > stat.st_size:
                # Files there at start are followed from their end; new or rotated ones from the beginning.
                positions[path] = (stat.st_ino, stat.st_size if first else 0)
            with open(path, errors='replace') as handle:
                handle.seek(positions[path][1])
                chunk = handle.read(1048576)
                positions[path] = (stat.st_ino, handle.tell())
            site = path.split('/')[3]
            for line in chunk.splitlines():
                match = LARAVEL.match(line)
                if match:
                    flush_pending(pending, path, site)
                    level = match.group(2).lower()
                    if level in ('warning', 'error', 'critical', 'alert', 'emergency'):
                        pending[path] = [('critical' if level in ('alert', 'emergency') else level), match.group(3), [line]]
                elif path in pending and len(pending[path][2]) < 200:
                    pending[path][2].append(line)
        for path in list(pending):
            flush_pending(pending, path, path.split('/')[3])
        first = False
        time.sleep(2)

def flush_pending(pending, path, site):
    item = pending.pop(path, None)
    if item:
        put(event(item[0], 'app:' + site, item[1].strip() or 'Log entry', 'laravel', '\n'.join(item[2])))

def send(events):
    body = json.dumps({'batch_id': str(uuid.uuid4()), 'events': events}).encode()
    request = urllib.request.Request(CONFIG['endpoint'], data=body, method='POST', headers={'Authorization': 'Bearer ' + CONFIG['token'], 'Content-Type': 'application/json', 'Accept': 'application/json', 'User-Agent': 'buildpusher-log-agent/1'})
    for attempt in range(3):
        try:
            urllib.request.urlopen(request, timeout=10).read()
            return
        except Exception:
            time.sleep(2 ** attempt)

def main():
    threading.Thread(target=journal, daemon=True).start()
    threading.Thread(target=files, daemon=True).start()
    minute, sent, dropped, batch, last = int(time.time() // 60), 0, 0, [], time.time()
    while True:
        try:
            item = LINES.get(timeout=1)
            if int(time.time() // 60) != minute:
                if dropped:
                    batch.append(event('warning', 'buildpusher-log-agent', '%d log lines dropped (over 600 a minute)' % dropped, 'agent'))
                minute, sent, dropped = int(time.time() // 60), 0, 0
            if sent < 600:
                batch.append(item)
                sent += 1
            else:
                dropped += 1
        except queue.Empty:
            pass
        if batch and (len(batch) >= 100 or time.time() - last >= 5):
            send(batch)
            batch, last = [], time.time()

main()
PYTHON;

    /**
     * Build the script that installs (or updates) the agent with where to send and the key, and starts it.
     *
     * @param  string  $endpoint  the ingest address
     * @param  string  $token  the environment's ingest key
     * @return string
     */
    public function install(string $endpoint, string $token): string
    {
        $agent = base64_encode(self::AGENT);
        $config = base64_encode((string) json_encode(['endpoint' => $endpoint, 'token' => $token], JSON_UNESCAPED_SLASHES));
        $unit = base64_encode("[Unit]\nDescription=BuildPusher log agent\nAfter=network-online.target\n\n[Service]\nType=simple\nExecStart=/usr/bin/python3 /usr/local/bin/buildpusher-log-agent\nRestart=always\nRestartSec=10\nNice=10\n\n[Install]\nWantedBy=multi-user.target\n");

        return <<<BASH
set -euo pipefail
command -v python3 >/dev/null || (apt-get update -qq && apt-get install -y -qq python3)
install -d -m 700 /etc/buildpusher
printf '%s' '{$config}' | base64 --decode > /etc/buildpusher/logs.json
chmod 600 /etc/buildpusher/logs.json
printf '%s' '{$agent}' | base64 --decode > /usr/local/bin/buildpusher-log-agent
chmod 755 /usr/local/bin/buildpusher-log-agent
printf '%s' '{$unit}' | base64 --decode > /etc/systemd/system/buildpusher-log-agent.service
systemctl daemon-reload
systemctl enable buildpusher-log-agent >/dev/null
systemctl restart buildpusher-log-agent
systemctl is-active --quiet buildpusher-log-agent
BASH;
    }

    /**
     * Build the script that stops and removes the agent and its key.
     *
     * @return string
     */
    public function remove(): string
    {
        return <<<'BASH'
systemctl disable --now buildpusher-log-agent >/dev/null 2>&1 || true
rm -f /etc/systemd/system/buildpusher-log-agent.service /usr/local/bin/buildpusher-log-agent /etc/buildpusher/logs.json
systemctl daemon-reload
BASH;
    }
}
