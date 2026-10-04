<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\ProviderType;
use App\Enums\SelectionKind;
use App\Models\BillingSelection;
use App\Models\Project;
use App\Models\Provider;
use App\Models\SecurityZone;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ZoneSecurityTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check a project's Cloudflare zones are listed, and their settings saved and applied on the Team plan only.
     *
     * @return void
     */
    public function test_zone_security_is_applied_at_cloudflare(): void
    {
        Http::fake([
            '*/zones/a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6' => Http::response(['success' => true, 'result' => ['name' => 'example.com']]),
            '*/settings/security_level' => Http::response(['success' => true]),
            '*/bot_management' => Http::response(['success' => true]),
        ]);
        $project = Project::factory()->withServices(['security', 'infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $cloudflare = Provider::factory()->type(ProviderType::Cloudflare)->create(['account_id' => $project->account_id]);
        $website = Website::factory()->create(['account_id' => $project->account_id, 'environment_id' => $project->environments()->firstOrFail()->id]);
        (new WebsiteDomain)->forceFill(['website_id' => $website->id, 'hostname' => 'shop.example.com', 'type' => 'primary', 'is_temporary' => false, 'dns_status' => 'active', 'ssl_status' => 'active',
            'dns_provider_id' => $cloudflare->id, 'dns_record_id' => 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6:rec1'])->save();
        $page = "/api/app/projects/{$project->id}/security/firewall";

        $this->actingAs($owner)->getJson($page)->assertOk()->assertJsonPath('zones.0.domains', ['shop.example.com'])->assertJsonPath('included', false);
        $this->actingAs($owner)->putJson("{$page}/a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6", ['security_level' => 'high'])->assertJsonValidationErrors('security_level');

        (new BillingSelection)->forceFill(['account_id' => $project->account_id, 'service' => 'security', 'kind' => SelectionKind::Tier, 'item_key' => 'team'])->save();
        $this->actingAs($owner)->putJson("{$page}/a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6", ['security_level' => 'high', 'bot_fight_mode' => '1', 'under_attack' => '1'])->assertJsonRedirect($page);
        $zone = SecurityZone::query()->sole();
        $this->assertSame(['high', true, true, 'example.com'], [$zone->security_level, $zone->bot_fight_mode, $zone->under_attack, $zone->zone_name]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/settings/security_level') && $request['value'] === 'under_attack');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/bot_management') && $request['fight_mode'] === true);
        $this->actingAs($owner)->getJson($page)->assertJsonPath('zones.0.underAttack', true)->assertJsonPath('zones.0.level', 'high')->assertSee('example.com');

        $this->actingAs($owner)->putJson("{$page}/ffffffffffffffffffffffffffffffff", ['security_level' => 'high'])->assertJsonValidationErrors('security_level');
    }
}
