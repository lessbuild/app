<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('analytics')->create('visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('visit_key', 128);
            $table->string('visitor_hash', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->string('landing_path', 2048)->nullable();
            $table->string('exit_path', 2048)->nullable();
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('conversion_count')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'visit_key']);
            $table->index(['site_id', 'last_seen_at']);
            $table->index(['site_id', 'visitor_hash', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('visits');
    }
};
