# Deployer

## Run as a daemon

The included systemd installer configures the app to listen on every network
interface on port `8003`, restart after failures, and start automatically after
a reboot. On a new development host it also creates a local SQLite database.

```bash
sudo ./scripts/install-daemon.sh
```

Pass a public IP explicitly when automatic detection is not appropriate:

```bash
sudo ./scripts/install-daemon.sh 203.0.113.10
```

The app will be available at `http://PUBLIC_IP:8003`. Ensure that TCP port 8003
is allowed by the host firewall and cloud-provider firewall.

### Todo
 

- [ ] Need to refactor scripts -- too much logic in them.
- [ ] Fix Public Key and Private Key Recognition
- [ ] Refactor Server providers so they use a common interface
- [ ] Recipes scripts take scripts from user defined data
- [ ] Add a way to define recipes
- [ ] Save logs to a file and output via that rather than a direct SSH command
- [ ] replace runner private key with the generated server key
- [ ] When selecting app type, use different scripts rather than them all.
