<?php

namespace Tests\Feature\Core;

use App\Core\Models\WorkspaceFeedback;
use App\Modules\Deployer\Models\ProductFeedback;
use App\Modules\Deployer\Services\Migration\ImportFeedbackIntoCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeployerFeedbackImportTest extends TestCase
{
    private string $workspaceId;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createDeployerTables();
        $this->addMappedWorkspace();
        $this->addFeedback();
    }

    protected function tearDown(): void
    {
        Schema::connection('deployer')->dropIfExists('product_feedback');

        foreach (['workspace_feedback', 'legacy_identity_maps', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_preview_apply_and_repeat_preserve_feedback_and_source_history(): void
    {
        $importer = app(ImportFeedbackIntoCore::class);

        $preview = $importer->run();

        $this->assertSame(1, $preview['feedback_seen']);
        $this->assertSame(1, $preview['feedback_ready']);
        $this->assertSame(0, $preview['feedback_imported']);
        $this->assertDatabaseCount('workspace_feedback', 0, 'core');
        $this->assertDatabaseCount('legacy_identity_maps', 2, 'core');

        $applied = $importer->run(apply: true);
        $feedback = WorkspaceFeedback::query()->firstOrFail();
        $mapping = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')
            ->where('source_entity', 'product_feedback')
            ->where('source_id', '501')
            ->first();

        $this->assertSame(1, $applied['feedback_imported']);
        $this->assertSame('deployer', $feedback->product);
        $this->assertSame($this->workspaceId, $feedback->workspace_id);
        $this->assertSame($this->userId, $feedback->user_id);
        $this->assertSame('It failed during release.', $feedback->description);
        $this->assertSame('Steps are private.', $feedback->reproduction_steps);
        $this->assertSame('We are investigating.', $feedback->review_response);
        $this->assertSame('2025-06-01 08:00:00', $feedback->getRawOriginal('created_at'));
        $this->assertSame('workspace_feedback', $mapping->canonical_entity);
        $this->assertSame($feedback->getKey(), $mapping->canonical_id);
        $mappingMetadata = json_decode($mapping->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($this->workspaceId, $mappingMetadata['workspace_id']);
        $this->assertNotSame(
            'It failed during release.',
            DB::connection('core')->table('workspace_feedback')->value('description'),
        );

        $secondRun = $importer->run(apply: true);

        $this->assertSame(1, $secondRun['feedback_already_current']);
        $this->assertDatabaseCount('workspace_feedback', 1, 'core');
    }

    public function test_unmapped_submitter_is_held_then_imported_after_reconciliation(): void
    {
        DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')
            ->where('source_entity', 'user')
            ->where('source_id', '8')
            ->delete();

        $firstRun = app(ImportFeedbackIntoCore::class)->run(apply: true);

        $this->assertSame(1, $firstRun['feedback_blocked']);
        $this->assertSame(0, $firstRun['feedback_imported']);
        $this->assertSame('needs_review', DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')
            ->where('source_entity', 'product_feedback')
            ->value('status'));

        $this->map('user', '8', 'user', $this->userId);
        $secondRun = app(ImportFeedbackIntoCore::class)->run(apply: true);

        $this->assertSame(1, $secondRun['feedback_imported']);
        $this->assertSame('reconciled', DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')
            ->where('source_entity', 'product_feedback')
            ->value('status'));
    }

    public function test_source_changes_update_the_same_core_record_and_keep_created_time(): void
    {
        app(ImportFeedbackIntoCore::class)->run(apply: true);
        $original = WorkspaceFeedback::query()->firstOrFail();

        $source = ProductFeedback::query()->findOrFail(501);
        $source->forceFill([
            'title' => 'Updated report title',
            'description' => 'Updated encrypted details.',
        ])->save();

        $result = app(ImportFeedbackIntoCore::class)->run(apply: true);
        $updated = WorkspaceFeedback::query()->firstOrFail();

        $this->assertSame(1, $result['feedback_updated']);
        $this->assertSame($original->getKey(), $updated->getKey());
        $this->assertSame('2025-06-01 08:00:00', $updated->getRawOriginal('created_at'));
        $this->assertSame('Updated report title', $updated->title);
        $this->assertSame('Updated encrypted details.', $updated->description);
    }

    public function test_unreadable_source_ciphertext_is_held_without_copying_ciphertext_or_error_text(): void
    {
        DB::connection('deployer')->table('product_feedback')
            ->where('id', 501)
            ->update(['description' => 'not-valid-ciphertext']);

        $report = app(ImportFeedbackIntoCore::class)->run(apply: true);
        $mapping = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')
            ->where('source_entity', 'product_feedback')
            ->where('source_id', '501')
            ->first();

        $this->assertSame(1, $report['feedback_blocked']);
        $this->assertSame(0, DB::connection('core')->table('workspace_feedback')->count());
        $this->assertStringContainsString('deployer_feedback_ciphertext_unreadable', $mapping->reconciliation_notes);
        $this->assertStringNotContainsString('not-valid-ciphertext', $mapping->reconciliation_notes);
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('email');
            $table->string('email_normalized')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product', 24);
            $table->string('source_entity', 100);
            $table->string('source_id', 191);
            $table->string('canonical_entity', 100)->nullable();
            $table->string('canonical_id', 26)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('batch_key', 100)->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['source_product', 'source_entity', 'source_id']);
        });

        Schema::connection('core')->create('workspace_feedback', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->char('reviewed_by_user_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('category', 20);
            $table->string('severity', 20)->default('normal');
            $table->string('status', 20)->default('open');
            $table->string('title', 160);
            $table->text('description');
            $table->text('reproduction_steps')->nullable();
            $table->text('review_response')->nullable();
            $table->string('page', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDeployerTables(): void
    {
        Schema::connection('deployer')->create('product_feedback', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('category', 20);
            $table->string('severity', 20);
            $table->string('status', 20)->default('open');
            $table->string('title', 160);
            $table->text('description');
            $table->text('reproduction_steps')->nullable();
            $table->text('review_response')->nullable();
            $table->string('page', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    private function addMappedWorkspace(): void
    {
        $this->workspaceId = (string) Str::ulid();
        $this->userId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $this->userId,
            'name' => 'Workspace member',
            'email' => 'member@example.test',
            'email_normalized' => 'member@example.test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Deployer workspace',
            'slug' => 'deployer-feedback-test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->map('organization', '42', 'workspace', $this->workspaceId);
        $this->map('user', '8', 'user', $this->userId);
    }

    private function addFeedback(): void
    {
        DB::connection('deployer')->table('product_feedback')->insert([
            'id' => 501,
            'organization_id' => 42,
            'user_id' => 8,
            'reviewed_by' => null,
            'category' => 'bug',
            'severity' => 'high',
            'status' => 'resolved',
            'title' => 'Deployment failed',
            'description' => Crypt::encryptString('It failed during release.'),
            'reproduction_steps' => Crypt::encryptString('Steps are private.'),
            'review_response' => Crypt::encryptString('We are investigating.'),
            'page' => '/projects/example',
            'resolved_at' => '2025-06-02 09:30:00',
            'created_at' => '2025-06-01 08:00:00',
            'updated_at' => '2025-06-02 09:30:00',
        ]);
    }

    private function map(string $sourceEntity, string $sourceId, string $canonicalEntity, string $canonicalId): void
    {
        DB::connection('core')->table('legacy_identity_maps')->updateOrInsert(
            [
                'source_product' => 'deployer',
                'source_entity' => $sourceEntity,
                'source_id' => $sourceId,
            ],
            [
                'id' => (string) Str::ulid(),
                'canonical_entity' => $canonicalEntity,
                'canonical_id' => $canonicalId,
                'status' => 'reconciled',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
