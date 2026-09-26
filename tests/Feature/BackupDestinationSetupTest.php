<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\BackupDestination;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Models\WebsiteBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupDestinationSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_backup_page_explains_spaces_setup_and_offers_presets(): void
    {
        $owner = User::factory()->create();
        $destination = $this->destination($owner);

        $response = $this->actingAs($owner)->get(route('backups.index'));

        $response->assertOk()
            ->assertSee('DigitalOcean Spaces')
            ->assertSee('A DigitalOcean control-plane token is different.')
            ->assertSee('No active website or server is required.')
            ->assertSee('https://&lt;region&gt;.digitaloceanspaces.com', false)
            ->assertSee('data-modal-trigger="backup-destination-create-dialog"', false)
            ->assertSee('data-modal-trigger="backup-destination-edit-'.$destination->id.'"', false)
            ->assertSee('id="backup-destination-edit-'.$destination->id.'"', false)
            ->assertSee('data-modal-content-loaded="false"', false)
            ->assertDontSee('name="secret-key"', false)
            ->assertViewHas('destinationPresets');
    }

    public function test_backup_destination_create_and_selected_edit_forms_are_url_backed_dialog_components(): void
    {
        $owner = User::factory()->create();
        $destination = $this->destination($owner);

        $createDialogUrl = route('backups.index', ['dialog' => 'add-destination']);
        $createDialog = $this->actingAs($owner)->get($createDialogUrl)
            ->assertSuccessful()
            ->assertSee('id="backup-destination-create-dialog"', false)
            ->assertSee('data-modal-initial-open="true"', false);

        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*\bname="_backup_destination_form")(?=[^>]*\bvalue="create")[^>]*>/s',
            $createDialog->getContent(),
        );

        $editDialogUrl = route('backups.index', ['dialog' => 'edit-destination-'.$destination->id]);
        $editDialog = $this->actingAs($owner)->get($editDialogUrl)
            ->assertSuccessful()
            ->assertSee('id="backup-destination-edit-'.$destination->id.'"', false)
            ->assertSee('data-modal-initial-open="true"', false)
            ->assertDontSee('secret-key')
            ->assertDontSee('access-key');

        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*\bname="_backup_destination_form")(?=[^>]*\bvalue="edit")[^>]*>/s',
            $editDialog->getContent(),
        );
    }

    public function test_backup_destination_validation_reopens_the_requested_dialog_without_flashing_credentials(): void
    {
        $owner = User::factory()->create();
        $destination = $this->destination($owner);

        $createDialogUrl = route('backups.index', ['dialog' => 'add-destination']);
        $createResponse = $this->actingAs($owner)
            ->from($createDialogUrl)
            ->followingRedirects()
            ->post(route('backups.destinations.store', ['dialog' => 'add-destination']), [
                '_backup_destination_form' => 'create',
                'storage_provider' => 'digitalocean_spaces',
                'name' => '',
                'endpoint' => '',
                'bucket' => '',
                'region' => 'lon1',
                'access_key' => 'do-not-flash-access',
                'secret_key' => 'do-not-flash-secret',
                'path_prefix' => 'buildpusher',
            ])
            ->assertSuccessful();

        $createResponse->assertSee('id="backup-destination-create-dialog"', false)
            ->assertSee('data-modal-initial-open="true"', false)
            ->assertSee('The name field is required.')
            ->assertDontSee('do-not-flash-access')
            ->assertDontSee('do-not-flash-secret');

        $editDialogUrl = route('backups.index', ['dialog' => 'edit-destination-'.$destination->id]);
        $editResponse = $this->actingAs($owner)
            ->from($editDialogUrl)
            ->followingRedirects()
            ->patch(route('backups.destinations.update', $destination), [
                '_backup_destination_form' => 'edit',
                '_backup_destination_id' => $destination->id,
                'storage_provider' => 'digitalocean_spaces',
                'name' => '',
                'endpoint' => $destination->endpoint,
                'bucket' => $destination->bucket,
                'region' => $destination->region,
                'access_key' => '',
                'secret_key' => '',
                'path_prefix' => $destination->path_prefix,
            ])
            ->assertSuccessful();

        $editResponse->assertSee('id="backup-destination-edit-'.$destination->id.'"', false)
            ->assertSee('data-modal-initial-open="true"', false)
            ->assertSee('The name field is required.');
    }

    public function test_spaces_preset_derives_its_endpoint_without_persisting_form_metadata(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('backups.destinations.store'), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => 'London backups',
            'endpoint' => '',
            'bucket' => 'buildpusher-backups',
            'region' => 'lon1',
            'access_key' => 'spaces-access',
            'secret_key' => 'spaces-secret',
            'path_prefix' => 'buildpusher',
        ])->assertRedirect();

        $destination = BackupDestination::query()->sole();
        $this->assertSame('https://lon1.digitaloceanspaces.com', $destination->endpoint);
        $this->assertArrayNotHasKey('storage_provider', $destination->getAttributes());
        $this->assertSame('spaces-access', $destination->access_key);
        $this->assertNotSame('spaces-access', DB::table('backup_destinations')->value('access_key'));
    }

    public function test_invalid_destination_credentials_are_not_flashed_on_validation_failure(): void
    {
        $owner = User::factory()->create();

        $response = $this->from(route('backups.index'))->actingAs($owner)->post(route('backups.destinations.store'), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => '',
            'endpoint' => '',
            'bucket' => '',
            'region' => 'lon1',
            'access_key' => 'do-not-flash-access',
            'secret_key' => 'do-not-flash-secret',
            'path_prefix' => 'buildpusher',
        ]);

        $response->assertRedirect(route('backups.index'));
        $this->assertSame('', (string) session()->get('_old_input.access_key', ''));
        $this->assertSame('', (string) session()->get('_old_input.secret_key', ''));
    }

    public function test_update_rotates_credentials_without_requiring_old_values(): void
    {
        [$owner] = $this->infrastructure();
        $destination = $this->destination($owner);
        $destination->update(['last_verified_at' => now(), 'last_error' => 'Old connection error']);

        $this->actingAs($owner)->patch(route('backups.destinations.update', $destination), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => $destination->name,
            'endpoint' => $destination->endpoint,
            'bucket' => $destination->bucket,
            'region' => $destination->region,
            'access_key' => 'new-access',
            'secret_key' => '',
            'path_prefix' => $destination->path_prefix,
        ])->assertSessionHas('success', 'Backup destination updated. Verify it before the next backup.');

        $updated = $destination->fresh();
        $this->assertSame('new-access', $updated->access_key);
        $this->assertSame('secret-key', $updated->secret_key);
        $this->assertSame('repository-secret', $updated->repository_password);
        $this->assertNull($updated->last_verified_at);
        $this->assertNull($updated->last_error);
    }

    public function test_update_cannot_move_a_destination_with_retained_snapshots(): void
    {
        [$owner, $website] = $this->infrastructure();
        $destination = $this->destination($owner);
        $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'abcdef1234567890',
        ]);

        $this->actingAs($owner)->patch(route('backups.destinations.update', $destination), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => $destination->name,
            'endpoint' => $destination->endpoint,
            'bucket' => 'different-bucket',
            'region' => $destination->region,
            'access_key' => '',
            'secret_key' => '',
            'path_prefix' => $destination->path_prefix,
        ])->assertSessionHas('error', 'Create a new destination instead of moving one that contains retained snapshots.');

        $this->assertSame('buildpusher-backups', $destination->fresh()->bucket);
    }

    public function test_connection_test_writes_reads_and_deletes_a_temporary_object_without_a_server(): void
    {
        $owner = User::factory()->create();
        $destination = $this->destination($owner);
        $methods = [];
        $urls = [];
        $payload = '';
        Http::fake(function (HttpRequest $request) use (&$methods, &$urls, &$payload) {
            $methods[] = $request->method();
            $urls[] = $request->url();

            if ($request->method() === 'PUT') {
                $payload = $request->body();

                return Http::response('', 200);
            }

            if ($request->method() === 'GET') {
                return Http::response($payload, 200);
            }

            return Http::response('', 204);
        });

        $this->actingAs($owner)
            ->post(route('backups.destinations.test', $destination))
            ->assertSessionHas('success', 'Backup destination verified and ready for backups.');

        $this->assertNotNull($destination->fresh()->last_verified_at);
        $this->assertNull($destination->fresh()->last_error);
        $this->assertSame(['PUT', 'GET', 'DELETE'], $methods);
        $this->assertCount(3, $urls);
        $this->assertSame($urls[0], $urls[1]);
        $this->assertSame($urls[1], $urls[2]);
        $this->assertStringContainsString('/buildpusher-backups/buildpusher/connection-tests/', $urls[0]);
        $this->assertNotSame('', $payload);
        Http::assertSent(function (HttpRequest $request): bool {
            return $request->method() === 'PUT'
                && $request->hasHeader('Authorization')
                && str_starts_with((string) $request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=access-key/');
        });
    }

    public function test_connection_failure_is_sanitized_and_recorded(): void
    {
        $owner = User::factory()->create();
        $destination = $this->destination($owner);
        Http::fake(fn () => Http::response('<Error><Code>InvalidAccessKeyId</Code><Message>secret-key was rejected</Message></Error>', 403));

        $this->actingAs($owner)->post(route('backups.destinations.test', $destination))
            ->assertSessionHas('error', function (string $message): bool {
                return str_contains($message, 'HTTP 403, InvalidAccessKeyId') && ! str_contains($message, 'secret-key');
            });

        $updated = $destination->fresh();
        $this->assertStringContainsString('HTTP 403, InvalidAccessKeyId', (string) $updated->last_error);
        $this->assertStringNotContainsString('secret-key', (string) $updated->last_error);
        $this->assertNull($updated->last_verified_at);
    }

    public function test_connection_failure_cleans_up_the_temporary_object_after_a_read_error(): void
    {
        $owner = User::factory()->create();
        $destination = $this->destination($owner);
        $methods = [];
        Http::fake(function (HttpRequest $request) use (&$methods) {
            $methods[] = $request->method();

            return match ($request->method()) {
                'PUT' => Http::response('', 200),
                'GET' => Http::response('', 200),
                'DELETE' => Http::response('', 204),
                default => Http::response('', 405),
            };
        });

        $this->actingAs($owner)->post(route('backups.destinations.test', $destination))
            ->assertSessionHas('error', 'Backup destination read verification returned unexpected content.');

        $this->assertSame(['PUT', 'GET', 'DELETE'], $methods);
        $this->assertNull($destination->fresh()->last_verified_at);
    }

    public function test_non_manager_cannot_edit_or_test_a_destination(): void
    {
        $owner = User::factory()->create();
        $destination = $this->destination($owner);
        $developer = User::factory()->create(['current_organization_id' => $owner->current_organization_id]);
        $owner->currentOrganization->members()->attach($developer->id, ['role' => 'developer']);

        $this->actingAs($developer)->patch(route('backups.destinations.update', $destination), [
            'storage_provider' => 'digitalocean_spaces',
            'name' => 'Changed',
            'endpoint' => $destination->endpoint,
            'bucket' => $destination->bucket,
            'region' => $destination->region,
            'path_prefix' => $destination->path_prefix,
        ])->assertForbidden();
        $this->actingAs($developer)->post(route('backups.destinations.test', $destination))->assertForbidden();

        $this->assertSame('Spaces', $destination->fresh()->name);
    }

    /** @return array{User, Website} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'DigitalOcean', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'token', 'description' => 'Cloud',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'Production', 'public_ip' => '203.0.113.20',
            'ssh_private_key' => 'private', 'mysql_root_password' => 'mysql-secret',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Application', 'description' => 'Website',
            'environment' => 'APP_KEY=secret', 'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $website];
    }

    private function destination(User $owner): BackupDestination
    {
        return $owner->currentOrganization->backupDestinations()->create([
            'created_by' => $owner->id,
            'name' => 'Spaces', 'endpoint' => 'https://lon1.digitaloceanspaces.com', 'bucket' => 'buildpusher-backups',
            'region' => 'lon1', 'access_key' => 'access-key', 'secret_key' => 'secret-key',
            'repository_password' => 'repository-secret', 'path_prefix' => 'buildpusher',
        ]);
    }
}
