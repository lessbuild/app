<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            // ASCII (punycode), lowercase, no trailing dot.
            $table->string('hostname', 253);
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'hostname']);
            $table->index('hostname');
        });

        // Anyone may claim a hostname, but only one project can hold it verified.
        // Partial unique indexes work the same way on PostgreSQL and SQLite.
        DB::statement('CREATE UNIQUE INDEX domains_verified_hostname_unique ON domains (hostname) WHERE verified_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
