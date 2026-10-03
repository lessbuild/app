<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Models\Environment;
use App\Models\PendingVariableChange;
use App\Models\Project;
use App\Models\StorageBucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class StorageBucketsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check buckets: created or checked over the S3 API before saving, the keys encrypted, attached to an environment
     * as AWS_* variables (or asked for where approval is needed), and managed only by people who manage Infrastructure.
     *
     * @return void
     */
    public function test_buckets_are_created_checked_and_attached_to_environments(): void
    {
        Http::fake([
            'https://acct.r2.cloudflarestorage.com/acme-uploads' => Http::response('', 200),
            'https://acct.r2.cloudflarestorage.com/acme-secret' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403),
        ]);
        $project = Project::factory()->withServices(['infrastructure', 'deploy'])->create();
        $owner = $this->ownerOf($project);
        [$production, $staging] = [$project->environments()->where('slug', 'production')->firstOrFail(), Environment::factory()->create(['project_id' => $project->id, 'slug' => 'staging', 'name' => 'Staging', 'kind' => 'staging'])];
        $base = "/projects/{$project->id}/infrastructure/storage";
        $form = ['name' => 'Uploads', 'storage_provider' => 'cloudflare_r2', 'region' => 'auto', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'bucket' => 'acme-uploads', 'access_key' => 'AKIDEXAMPLE', 'secret_key' => 's3cr3t'];

        $this->actingAs($owner)->get($base)->assertOk()->assertSee(__('Add a bucket'));
        $this->actingAs($owner)->post($base, [...$form, 'bucket' => 'acme-secret'])->assertSessionHasErrors('bucket');
        $this->assertSame(0, StorageBucket::query()->count());
        $this->actingAs($owner)->post($base, [...$form, 'bucket' => 'Bad_Name'])->assertSessionHasErrors('bucket');

        $this->actingAs($owner)->post($base, [...$form, 'create' => '1'])->assertRedirect($base);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->url() === 'https://acct.r2.cloudflarestorage.com/acme-uploads' && str_contains((string) $request->header('Authorization')[0], 'AWS4-HMAC-SHA256'));
        $bucket = StorageBucket::query()->sole();
        $this->assertNotSame('s3cr3t', $bucket->getRawOriginal('secret_key'));
        $this->actingAs($owner)->post($base, $form)->assertSessionHasErrors('bucket');

        $this->actingAs($owner)->post("{$base}/{$bucket->id}/attach", ['environment_id' => $production->id])->assertRedirect($base);
        $variables = $production->variables()->pluck('value', 'key')->all();
        $this->assertSame(['s3', 'AKIDEXAMPLE', 's3cr3t', 'auto', 'acme-uploads', 'https://acct.r2.cloudflarestorage.com', 'true'], [
            $variables['FILESYSTEM_DISK'], $variables['AWS_ACCESS_KEY_ID'], $variables['AWS_SECRET_ACCESS_KEY'], $variables['AWS_DEFAULT_REGION'], $variables['AWS_BUCKET'], $variables['AWS_ENDPOINT'], $variables['AWS_USE_PATH_STYLE_ENDPOINT'],
        ]);
        $this->assertTrue((bool) $production->variables()->where('key', 'AWS_SECRET_ACCESS_KEY')->value('is_secret'));
        $this->actingAs($owner)->get($base)->assertSee('Attached to '.$production->name)->assertDontSee('s3cr3t');

        $staging->forceFill(['require_variable_approval' => true])->save();
        $this->actingAs($owner)->post("{$base}/{$bucket->id}/attach", ['environment_id' => $staging->id])->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'approval'));
        $this->assertSame(0, $staging->variables()->count());
        $this->assertSame(7, PendingVariableChange::query()->where('environment_id', $staging->id)->count());

        $viewer = User::factory()->create();
        $this->addMember($project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->post($base, $form)->assertForbidden();
        $this->actingAs($viewer)->delete("{$base}/{$bucket->id}")->assertForbidden();
        $this->actingAs($owner)->delete("{$base}/{$bucket->id}")->assertRedirect($base);
        $this->assertSame(0, StorageBucket::query()->count());
    }
}
