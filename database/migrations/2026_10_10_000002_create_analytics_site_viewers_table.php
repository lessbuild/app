<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** People with view-only access to one site's report, each through their own link. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_site_viewers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_site_viewers');
    }
};
