<?php

use App\Modules\Deployer\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('deployer')->create('environment_blueprint_recipes', function (Blueprint $table): void {
            $table->id();
            $table->string('step_id', 26)->index();
            $table->unsignedBigInteger('actor_source_id');
            $table->unsignedBigInteger('workspace_source_id');
            $table->string('canonical_project_id', 191);
            $table->string('canonical_environment_id', 191);
            $table->string('environment_key', 60);
            $table->foreignIdFor(Environment::class)->constrained()->cascadeOnDelete();

            // Source IDs are historical provenance, so source deletion must not remove the snapshot.
            $table->unsignedBigInteger('source_recipe_id');
            $table->unsignedBigInteger('source_user_id')->nullable();
            $table->unsignedBigInteger('source_organization_id')->nullable();
            $table->unsignedBigInteger('source_gallery_recipe_id')->nullable();
            $table->string('source_name', 255);
            $table->text('source_description')->nullable();
            $table->boolean('source_is_published')->default(false);
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('source_revision_at')->nullable();
            $table->timestamp('source_published_at')->nullable();
            $table->timestamp('source_gallery_revision_at')->nullable();

            $table->longText('script_snapshot');
            $table->char('script_fingerprint', 64);
            $table->char('binding_fingerprint', 64);
            $table->unsignedSmallInteger('position');

            // A missing installed recipe remains a tombstone; a retry must not silently create another copy.
            $table->unsignedBigInteger('installed_recipe_id')->nullable();
            $table->char('install_receipt_fingerprint', 64)->nullable();
            $table->timestamp('install_attempted_at')->nullable();
            $table->timestamps();

            $table->unique(['step_id', 'environment_id', 'source_recipe_id'], 'environment_blueprint_recipe_binding_unique');
            $table->index(['environment_id', 'source_organization_id', 'position'], 'environment_blueprint_recipe_visibility');
            $table->index(['actor_source_id', 'workspace_source_id'], 'environment_blueprint_recipe_actor_workspace');
        });
    }

    public function down(): void
    {
        Schema::connection('deployer')->dropIfExists('environment_blueprint_recipes');
    }
};
