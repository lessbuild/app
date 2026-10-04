<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Contracts\Security\VulnerabilityDatabase;
use App\Enums\SelectionKind;
use App\Models\BillingSelection;
use App\Models\Build;
use App\Models\Project;
use App\Models\Repository;
use App\Models\SecurityFinding;
use App\Models\Website;
use App\Services\Deploy\SecurityGate;
use App\Services\Infrastructure\ProvisioningCallbackUrl;
use App\Support\Security\LockFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DependencySecurityTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * A composer.lock with one vulnerable package and one development package.
     *
     * @var string
     */
    private const COMPOSER = '{"packages":[{"name":"guzzlehttp/guzzle","version":"7.4.1"},{"name":"laravel/framework","version":"v11.0.0"}],"packages-dev":[{"name":"phpunit/phpunit","version":"10.0.0"}]}';

    /**
     * Answer the vulnerability database from a fixed list.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(VulnerabilityDatabase::class, new class implements VulnerabilityDatabase
        {
            /**
             * Say guzzle 7.4.1 has a critical advisory, phpunit 10.0.0 a high one, and nothing else is affected.
             *
             * @param  list<array{ecosystem: string, name: string, version: string}>  $packages
             * @return array<int, list<array{id: string, severity: string, summary: string, fixed: string|null, url: string}>>
             */
            public function lookup(array $packages): array
            {
                $results = [];
                foreach ($packages as $index => $package) {
                    $results[$index] = match ($package['name'].'@'.$package['version']) {
                        'guzzlehttp/guzzle@7.4.1' => [['id' => 'GHSA-guzz', 'severity' => 'critical', 'summary' => 'Cookie leak on redirect', 'fixed' => '7.4.5', 'url' => 'https://osv.dev/vulnerability/GHSA-guzz']],
                        'phpunit/phpunit@10.0.0' => [['id' => 'GHSA-unit', 'severity' => 'high', 'summary' => 'Code execution', 'fixed' => null, 'url' => 'https://osv.dev/vulnerability/GHSA-unit']],
                        default => [],
                    };
                }

                return $results;
            }
        });
    }

    /**
     * Check reading Composer and npm lock files (v1 and v3).
     *
     * @return void
     */
    public function test_lock_files_are_read(): void
    {
        $this->assertSame([
            ['ecosystem' => 'Packagist', 'name' => 'guzzlehttp/guzzle', 'version' => '7.4.1', 'dev' => false],
            ['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => '11.0.0', 'dev' => false],
            ['ecosystem' => 'Packagist', 'name' => 'phpunit/phpunit', 'version' => '10.0.0', 'dev' => true],
        ], LockFiles::composer(self::COMPOSER));
        $v3 = '{"lockfileVersion":3,"packages":{"":{"name":"app"},"node_modules/axios":{"version":"1.6.0"},"node_modules/vite":{"version":"5.0.0","dev":true},"node_modules/a/node_modules/axios":{"version":"1.6.0"}}}';
        $this->assertSame([['ecosystem' => 'npm', 'name' => 'axios', 'version' => '1.6.0', 'dev' => false], ['ecosystem' => 'npm', 'name' => 'vite', 'version' => '5.0.0', 'dev' => true]], LockFiles::npm($v3));
        $v1 = '{"lockfileVersion":1,"dependencies":{"lodash":{"version":"4.17.20","dependencies":{"minimist":{"version":"1.2.0"}}}}}';
        $this->assertSame(['lodash', 'minimist'], array_column(LockFiles::npm($v1), 'name'));
        $this->assertSame([], LockFiles::composer('not json'));
    }

    /**
     * Check the scheduled scan reads the live lock files, records findings (development packages one level lower),
     * and the deploy gate blocks until the risk is accepted.
     *
     * @return void
     */
    public function test_live_packages_are_scanned_and_the_gate_blocks_risky_deploys(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['security', 'deploy', 'infrastructure'])->create();
        (new BillingSelection)->forceFill(['account_id' => $project->account_id, 'service' => 'security', 'kind' => SelectionKind::Tier, 'item_key' => 'pro'])->save();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $website = Website::factory()->create(['account_id' => $project->account_id, 'environment_id' => $environment->id, 'name' => 'Shop']);
        $this->shell->reply("==composer.lock\n".base64_encode(self::COMPOSER)."\n");
        $page = "/api/app/projects/{$project->id}/security";

        $this->actingAs($owner)->postJson("{$page}/scans", ['kind' => 'dependencies'])->assertSuccessful();
        $this->assertStringContainsString('composer.lock package-lock.json', $this->shell->ran[0]['command']);
        $guzzle = SecurityFinding::query()->where('title', 'like', 'guzzlehttp/guzzle%')->sole();
        $this->assertSame(['critical', 'Shop', 'Update guzzlehttp/guzzle to 7.4.5 or later.', "website:{$website->id}"], [$guzzle->severity, $guzzle->subject, $guzzle->fix, $guzzle->scope]);
        $this->assertSame('medium', SecurityFinding::query()->where('title', 'like', 'phpunit/phpunit%')->value('severity'), 'Development packages count one level lower.');

        $this->actingAs($owner)->getJson($page)->assertOk()->assertJsonPath('gateIncluded', true)->assertJsonFragment(['id' => $environment->id, 'name' => $environment->name, 'gate' => null]);
        $this->actingAs($owner)->putJson("{$page}/gate/{$environment->id}", ['security_gate' => 'critical'])->assertJsonRedirect($page);
        $this->assertSame('critical', $environment->refresh()->security_gate);

        $repository = Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'environment_id' => $environment->id]);
        $build = Build::factory()->create(['repository_id' => $repository->id, 'environment_id' => $environment->id, 'environment_payload' => ['security_gate' => 'critical']]);
        $script = app(SecurityGate::class)->commands($build->load('repository.website', 'website'));
        $this->assertStringContainsString('composer=@', $script);
        $this->assertStringContainsString('/deployment/callback/security', $script);

        $gate = fn () => $this->post(ProvisioningCallbackUrl::buildSecurityGate($build), ['composer' => UploadedFile::fake()->createWithContent('composer.lock', self::COMPOSER)]);
        $this->assertStringStartsWith('BLOCK Security stopped this deploy: guzzlehttp/guzzle 7.4.1 (GHSA-guzz)', (string) $gate()->assertOk()->getContent());
        $this->post("/builds/{$build->id}/deployment/callback/security")->assertForbidden();

        $this->actingAs($owner)->putJson("{$page}/findings/{$guzzle->id}", ['status' => 'ignored', 'reason' => 'Not used for redirects'])->assertSuccessful();
        $this->assertStringStartsWith('PASS', (string) $gate()->getContent(), 'Accepting the risk lets deploys through.');

        $build->forceFill(['environment_payload' => []])->save();
        $this->assertStringContainsString('No Security gate', app(SecurityGate::class)->commands($build->refresh()));
    }

    /**
     * Check the free plan can't turn the gate on.
     *
     * @return void
     */
    public function test_the_gate_needs_a_paid_plan(): void
    {
        $project = Project::factory()->withServices(['security', 'deploy'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->firstOrFail();
        $this->actingAs($owner)->putJson("/api/app/projects/{$project->id}/security/gate/{$environment->id}", ['security_gate' => 'high'])->assertJsonValidationErrors('security_gate');
        $this->assertNull($environment->refresh()->security_gate);
    }
}
