<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5 part 4: requests for access while registration is closed, and the invitations that answer them. */
return new class extends Migration
{
    /**
     * Create the access requests table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('email_hash', 64)->unique();
            $table->text('email');
            $table->text('name');
            $table->text('company')->nullable();
            $table->string('team_size', 8)->nullable();
            $table->text('use_case');
            $table->string('status', 16)->default('pending');
            $table->text('review_notes')->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at', 6)->nullable();
            $table->string('invitation_token_hash', 64)->nullable()->unique();
            $table->timestamp('invited_at', 6)->nullable();
            $table->timestamp('invitation_expires_at', 6)->nullable();
            $table->timestamp('accepted_at', 6)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps(6);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Drop the access requests table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
