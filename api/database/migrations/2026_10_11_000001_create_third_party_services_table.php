<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of services a project depends on (GitHub, Cloudflare…), with their public status.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('third_party_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('name', 80);
            $table->string('url', 255);
            $table->string('indicator', 20)->default('unknown');
            $table->string('description', 255)->nullable();
            $table->json('affected')->nullable();
            $table->string('incident', 255)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'url']);
        });
    }

    /**
     * Drop the table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('third_party_services');
    }
};
