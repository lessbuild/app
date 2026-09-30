<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cloudflare zone-wide security settings a project chose: security level, bot fight mode and "under attack" mode. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('zone_id', 64);
            $table->string('zone_name')->nullable();
            $table->string('security_level', 16)->default('medium');
            $table->boolean('bot_fight_mode')->default(false);
            $table->boolean('under_attack')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_zones');
    }
};
