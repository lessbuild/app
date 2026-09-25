# Core access-request release

Application commit: fc316ca456e921aec104b56d7dc52da945e88dae  
Branch: feature/unified-platform  
Production release: /mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/fc316ca456e921aec104b56d7dc52da945e88dae

Core now serves the Deployer access-request form at buildpusher.com/request-access. It reuses Deployer's existing validated intake action, encrypted request model, notifications, plan catalog, and access-request rate limiter. Submitted requests and review history remain in the Deployer database; no migration, data copy, or database write was made in this release. The existing Deployer request URL remains available. Review and export actions still run on the Deployer module-admin page.

The release was staged and activated through the local production release symlink. Composer dependencies and public assets were reused, persistent storage was retained, and PHP-FPM reloaded successfully. Production route inspection confirmed the Buildpusher-hosted GET and POST routes; the POST route includes the existing access-requests throttle.

Static checks passed: PHP syntax, Pint, route registration, Blade view compilation, and git diff --check. Loopback HTTPS GET checks returned 200 for the Buildpusher homepage, pricing, and request-access page, plus the Deployer request-access compatibility page. The homepage and shared navigation link to the Core request page.

Automated tests were authored but not run, as requested. No access request was submitted during verification. Cross-host submission/review acceptance, Core platform-admin review, full status-page inventory, pricing/legal review, and complete D24/D23 acceptance remain open.
