<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Infrastructure part 5: inspecting website databases, extra database users, and copying one website's database into another. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('active_connections')->nullable();
            $table->json('tables')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('collected_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['website_id', 'created_at']);
        });

        Schema::create('database_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('username', 32);
            $table->text('password');
            $table->string('privilege', 10)->default('read');
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('expires_at', 6)->nullable();
            $table->timestamp('applied_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['website_id', 'username']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('database_clones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('target_website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['target_website_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_clones');
        Schema::dropIfExists('database_users');
        Schema::dropIfExists('database_snapshots');
    }
};
