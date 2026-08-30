<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ProvisioningCallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildRevisionAttestationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_manual_build_records_its_checked_out_revision_and_message(): void
    {
        $build = $this->build();
        $revision = strtoupper(str_repeat('a', 40));

        $this->post(ProvisioningCallbackUrl::buildRevision($build), [
            'revision' => $revision,
            'commit_message' => " Manual release\x01\n\nReady ",
        ])->assertNoContent();

        $build->refresh();
        $this->assertSame(strtolower($revision), $build->revision);
        $this->assertSame("Manual release\n\nReady", $build->commit_message);
    }

    public function test_pinned_build_accepts_only_the_revision_it_was_queued_for(): void
    {
        $revision = str_repeat('b', 40);
        $build = $this->build([
            'revision' => $revision,
            'commit_message' => 'Authenticated push',
        ]);

        $this->post(ProvisioningCallbackUrl::buildRevision($build), [
            'revision' => strtoupper($revision),
            'commit_message' => 'Verified from Git',
        ])->assertNoContent();
        $this->assertSame('Verified from Git', $build->fresh()->commit_message);

        $this->post(ProvisioningCallbackUrl::buildRevision($build), [
            'revision' => str_repeat('c', 40),
            'commit_message' => 'Wrong commit',
        ])->assertConflict()->assertJson(['status' => 'revision_mismatch']);

        $build->refresh();
        $this->assertSame($revision, $build->revision);
        $this->assertSame('Verified from Git', $build->commit_message);
    }

    public function test_unsigned_malformed_and_oversized_attestations_are_rejected(): void
    {
        $build = $this->build();

        $this->post(route('callbacks.build.revision', $build), [
            'revision' => str_repeat('a', 40),
        ])->assertForbidden();

        $this->post(ProvisioningCallbackUrl::buildRevision($build), [
            'revision' => 'main; touch /tmp/pwned',
        ])->assertSessionHasErrors('revision');

        $this->post(ProvisioningCallbackUrl::buildRevision($build), [
            'revision' => str_repeat('a', 40),
            'commit_message' => str_repeat('x', 501),
        ])->assertSessionHasErrors('commit_message');

        $this->assertNull($build->fresh()->revision);
    }

    public function test_stale_attestation_cannot_change_a_terminal_build(): void
    {
        $revision = str_repeat('d', 40);
        $build = $this->build([
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => $revision,
            'commit_message' => 'Released',
        ]);

        $this->post(ProvisioningCallbackUrl::buildRevision($build), [
            'revision' => str_repeat('e', 40),
            'commit_message' => 'Late report',
        ])->assertNoContent();

        $this->assertSame($revision, $build->fresh()->revision);
        $this->assertSame('Released', $build->fresh()->commit_message);
    }

    private function build(array $attributes = []): Build
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return $repository->builds()->create([
            'status' => Build::STATUS_RUNNING,
            'trigger_source' => Build::TRIGGER_MANUAL,
            ...$attributes,
        ]);
    }
}
