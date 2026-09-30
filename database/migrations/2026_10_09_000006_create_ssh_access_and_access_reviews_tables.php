<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** People's SSH keys, which servers they can use them on, and the record of each access review. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ssh_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('public_key');
            $table->string('fingerprint', 100);
            $table->timestamps();
            $table->unique(['user_id', 'fingerprint']);
        });
        Schema::create('server_ssh_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'user_id']);
        });
        Schema::create('security_access_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary');
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_access_reviews');
        Schema::dropIfExists('server_ssh_grants');
        Schema::dropIfExists('user_ssh_keys');
    }
};
