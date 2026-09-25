# Core marketing, pricing, and legal release

Release commit: 0607ace7355666fd954fa1fbff3d5f37a56803f2  
Branch: feature/unified-platform  
Production release: /mnt/volume_nyc1_1789401255960/buildpusher-unified/releases/0607ace7355666fd954fa1fbff3d5f37a56803f2

Core now owns the Buildpusher-hosted Privacy and Terms pages and the public pricing overview. Pricing reads Deployer and Monitor's current product catalogs independently. Analytics has no paid catalog in its current module configuration, so the page displays no invented Analytics tier or price and offers a contact path. Deployer's prior /pricing, /privacy, and /terms routes remain available on its product host.

The release was staged and activated on the local production host through the existing release symlink. It reuses the current vendor and compiled assets and the existing persistent application storage. It adds no database migration; production data was not changed. PHP-FPM reloaded successfully.

Static checks passed: PHP syntax, Pint, route registration, Blade view compilation, and git diff --check. Production route inspection confirmed buildpusher.com/pricing, /privacy, and /terms. Loopback HTTPS smoke checks returned 200 for the Buildpusher homepage, pricing, privacy, and terms pages, plus the Deployer compatibility pricing and privacy pages. The Buildpusher homepage and product pages link to Core pricing.

Automated tests were authored but not run, as requested. Public pricing and legal content review, Core access-request migration, status-page inventory, and full D24 acceptance remain open.
