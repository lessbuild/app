<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DiscoverabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_describe_themselves_for_search_and_sharing(): void
    {
        $this->get('/')->assertOk()->assertSee('<meta property="og:image" content="'.asset('images/og/default.png').'">', false)
            ->assertSee('summary_large_image')->assertSee('application/ld+json', false)->assertSee('"@type":"SoftwareApplication"', false);
        $this->get('/features/monitoring')->assertOk()->assertSee(asset('images/og/monitoring.png'));
        $this->assertFileExists(public_path('images/og/monitoring.png'));
        $this->get('/login')->assertOk()->assertDontSee('og:image');
    }

    public function test_public_pages_have_one_clean_title_a_canonical_address_and_structured_data(): void
    {
        $home = $this->get('/?utm_source=newsletter')->assertOk();
        $home->assertSee('<title>Deploy, monitor and analyse apps on your own servers · '.config('app.name').'</title>', false)
            ->assertSee('<link rel="canonical" href="'.route('home').'">', false)->assertSee('"@type":"Organization"', false)->assertSee('"@type":"WebSite"', false);

        $this->get('/features/deploy')->assertOk()->assertSee('"@type":"BreadcrumbList"', false)->assertSee('"@type":"FAQPage"', false);
        $this->get('/features/security')->assertOk()->assertSee(asset('images/og/security.png'));
        $this->assertFileExists(public_path('images/og/security.png'));
        $this->get(route('help.guide', 'create-a-server'))->assertOk()->assertSee('"@type":"TechArticle"', false)->assertSee('"@type":"BreadcrumbList"', false);
        $this->get('/pricing?billing=yearly')->assertOk()->assertSee('<link rel="canonical" href="'.route('pricing').'">', false);
        $this->get('/login')->assertOk()->assertDontSee('application/ld+json', false)->assertDontSee('rel="canonical"', false);
    }

    public function test_guests_are_told_which_language_a_page_is_in(): void
    {
        $this->get('/pricing', ['Accept-Language' => 'de'])->assertOk()->assertHeader('Content-Language', 'de')
            ->assertHeader('Vary', 'Accept-Language');
    }

    public function test_the_sitemap_and_robots_point_search_engines_at_the_public_pages(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.route('features', 'deploy').'</loc>', false)->assertSee('<loc>'.route('help.guide', 'create-a-server').'</loc>', false)
            ->assertSee('<loc>'.route('compare', 'laravel-forge').'</loc>', false)
            ->assertSee('<loc>'.route('changelog').'</loc><lastmod>'.config('changelog.0.date').'</lastmod>', false)
            ->assertSee('<loc>'.route('legal', 'terms').'</loc><lastmod>'.config('legal.effective_date').'</lastmod>', false);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /projects/')->assertSee('Sitemap: '.route('sitemap'));
    }

    public function test_the_changelog_comparisons_and_status_badge(): void
    {
        $this->get('/changelog')->assertOk()->assertSee('What’s new', false)->assertSee(config('changelog.0.title'));
        $this->get('/compare/vercel')->assertOk()->assertSee('Vercel')->assertSee('Which to choose')->assertSee('as of September 2026');
        $this->get('/compare/nobody')->assertNotFound();
        $this->get('/pricing')->assertSee(route('compare', 'plausible'));
        $this->get('/status/badge.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('<svg', false);
    }
}
