<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add build servers: an environment can build on another of the account's servers and pass the result to its
     * websites through one of the project's storage buckets.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->foreignId('build_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignId('artifact_bucket_id')->nullable()->constrained('storage_buckets')->nullOnDelete();
        });
        Schema::table('builds', function (Blueprint $table): void {
            $table->foreignId('build_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->string('build_phase', 10)->nullable();
            $table->string('artifact_key')->nullable();
        });
    }

    /**
     * Remove build servers.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('build_server_id');
            $table->dropColumn(['build_phase', 'artifact_key']);
        });
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('build_server_id');
            $table->dropConstrainedForeignId('artifact_bucket_id');
        });
    }
};
