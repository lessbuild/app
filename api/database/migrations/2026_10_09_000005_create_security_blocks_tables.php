<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Addresses Security blocked on a project's servers for attacking them, and each project's blocking settings. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('autoblock')->default(true);
            $table->unsignedSmallInteger('block_hours')->default(24);
            $table->json('allowlist')->nullable();
            $table->timestamps();
        });
        Schema::create('security_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('reason', 24);
            $table->unsignedInteger('hits')->default(0);
            $table->text('detail')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('lifted_at')->nullable();
            $table->foreignUlid('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'lifted_at']);
            $table->index(['server_id', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_blocks');
        Schema::dropIfExists('security_settings');
    }
};
