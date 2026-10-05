<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DiscoverabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that public pages give the app their link preview image and structured data, and the images exist.
     *
     * @return void
     */
    public function test_public_pages_describe_themselves_for_search_and_sharing(): void
    {
        $this->getJson('/api/app/site/home')->assertOk()->assertJsonPath('meta.image', asset('images/og/default.png'))
            ->assertJsonFragment(['@type' => 'SoftwareApplication', 'name' => config('app.name'), 'url' => route('home'), 'applicationCategory' => 'DeveloperApplication', 'operatingSystem' => 'Web',
                'description' => __((string) config('marketing.summary')), 'publisher' => ['@id' => route('home').'#organization'],
                'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD', 'description' => 'Free tier for every service']]);
        $this->getJson('/api/app/site/features/monitoring')->assertOk()->assertJsonPath('meta.image', asset('images/og/monitoring.png'));
        $this->assertFileExists(base_path('../web/public/images/og/monitoring.png'));
    }

    /**
     * Check that public pages have one title, a canonical address without tracking parameters, and structured data.
     *
     * @return void
     */
    public function test_public_pages_have_one_clean_title_a_canonical_address_and_structured_data(): void
    {
        $types = fn (string $path): array => array_column((array) $this->getJson($path)->assertOk()->json('meta.structuredData.@graph'), '@type');

        $this->getJson('/api/app/site/home?utm_source=newsletter')->assertJsonPath('meta.title', 'Deploy, monitor and analyse apps on your own servers')->assertJsonPath('meta.canonical', route('home'));
        $this->assertSame(['Organization', 'WebSite', 'SoftwareApplication'], $types('/api/app/site/home'));
        $this->assertSame(['Organization', 'WebSite', 'BreadcrumbList', 'FAQPage'], $types('/api/app/site/features/deploy'));
        $this->getJson('/api/app/site/features/security')->assertJsonPath('meta.image', asset('images/og/security.png'));
        $this->assertFileExists(base_path('../web/public/images/og/security.png'));
        $this->assertSame(['Organization', 'WebSite', 'BreadcrumbList', 'TechArticle'], $types('/api/app/help/create-a-server'));
        $this->getJson('/api/app/site/pricing?billing=yearly')->assertJsonPath('meta.canonical', route('pricing'));
    }

    /**
     * Check that guests are told which language a page is in.
     *
     * @return void
     */
    public function test_guests_are_told_which_language_a_page_is_in(): void
    {
        $this->getJson('/api/app/site/pricing', ['Accept-Language' => 'de'])->assertOk()->assertHeader('Content-Language', 'de')
            ->assertJsonPath('meta.title', __('Pricing: a free tier for every service', [], 'de'));
    }

    /**
     * Check that the sitemap and robots.txt point search engines at the public pages.
     *
     * @return void
     */
    public function test_the_sitemap_and_robots_point_search_engines_at_the_public_pages(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.route('features', 'deploy').'</loc>', false)->assertSee('<loc>'.route('help.guide', 'create-a-server').'</loc>', false)
            ->assertSee('<loc>'.route('compare', 'laravel-forge').'</loc>', false)
            ->assertSee('<loc>'.route('changelog').'</loc><lastmod>'.config('changelog.0.date').'</lastmod>', false)
            ->assertSee('<loc>'.route('legal', 'terms').'</loc><lastmod>'.config('legal.effective_date').'</lastmod>', false);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /projects/')->assertSee('Sitemap: '.route('sitemap'));
    }

    /**
     * Check the changelog, the comparison pages and the platform's status badge.
     *
     * @return void
     */
    public function test_the_changelog_comparisons_and_status_badge(): void
    {
        $this->getJson('/api/app/site/changelog')->assertOk()->assertJsonPath('entries.0.title', config('changelog.0.title'));
        $this->getJson('/api/app/site/compare/vercel')->assertOk()->assertJsonPath('copy.name', 'Vercel')->assertJsonPath('checked', 'September 2026');
        $this->getJson('/api/app/site/compare/nobody')->assertNotFound();
        $this->getJson('/api/app/site/pricing')->assertJsonFragment(['slug' => 'plausible']);
        $this->get('/status/badge.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('<svg', false);
    }
}
