<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create secret syncs (Doppler, 1Password Connect, AWS Secrets Manager into an environment's variables), and mark
     * the variables each one manages.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('secret_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('name', 80);
            $table->text('settings');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('last_result')->nullable();
            $table->timestamps();
        });
        Schema::table('environment_variables', function (Blueprint $table): void {
            $table->foreignId('secret_sync_id')->nullable()->constrained('secret_syncs')->nullOnDelete();
        });
    }

    /**
     * Drop them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('secret_sync_id');
        });
        Schema::dropIfExists('secret_syncs');
    }
};
