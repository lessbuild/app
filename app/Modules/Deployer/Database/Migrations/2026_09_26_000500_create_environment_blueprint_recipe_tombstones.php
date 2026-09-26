<?php

use App\Modules\Deployer\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('deployer')->create('environment_blueprint_recipe_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->string('step_id', 26);
            $table->foreignIdFor(Environment::class)->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamp('archived_at');
            $table->unsignedSmallInteger('signature_version');
            $table->string('signing_key_id', 64);
            $table->char('slot_commitment', 64);
            $table->char('tombstone_signature', 64);

            $table->unique(
                ['step_id', 'environment_id', 'position'],
                'environment_blueprint_recipe_tombstone_slot_unique',
            );
            $table->unique('slot_commitment', 'environment_blueprint_recipe_tombstone_commitment_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('deployer')->dropIfExists('environment_blueprint_recipe_tombstones');
    }
};
