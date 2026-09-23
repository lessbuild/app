<?php

namespace Tests\Unit;

use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\WebsiteCaddyConfiguration;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WebsiteCaddyConfigurationTest extends TestCase
{
    public function test_literal_ip_site_addresses_are_explicitly_http(): void
    {
        $configuration = new WebsiteCaddyConfiguration;

        $this->assertSame('http://192.0.2.10', $configuration->siteAddress('192.0.2.10'));
        $this->assertSame('http://[2001:db8::10]', $configuration->siteAddress('2001:db8::10'));
    }

    public function test_named_site_addresses_keep_default_caddy_https_behavior(): void
    {
        $configuration = new WebsiteCaddyConfiguration;

        $this->assertSame('example.test', $configuration->siteAddress('example.test'));
    }

    public function test_php_configuration_uses_the_explicit_ip_site_address(): void
    {
        $website = new Website([
            'url' => '192.0.2.10',
            'deployment_slug' => 'site-test',
        ]);
        $website->setRelation('domains', new Collection);

        $rendered = (new WebsiteCaddyConfiguration)->php($website, '/var/www/site-test/current/public');

        $this->assertStringStartsWith('http://192.0.2.10 {', $rendered);
    }
}
