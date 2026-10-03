<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5 part 1: the platform-admin flag and the trail of admin grants and admin actions. */
return new class extends Migration
{
    /**
     * Add the admin flag to users and create the admin event log.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false);
            $table->timestamp('platform_admin_granted_at')->nullable();
        });

        Schema::create('platform_admin_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('source', 20)->default('admin');
            $table->foreignUlid('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('subject_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('description', 500);
            $table->timestamp('created_at', 6);
            $table->index('created_at');
        });
    }

    /**
     * Drop the admin event log and flag.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_admin_events');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_platform_admin', 'platform_admin_granted_at']));
    }
};
