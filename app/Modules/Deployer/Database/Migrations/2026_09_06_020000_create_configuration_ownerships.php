<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_ownerships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('configuration_review_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment_slug', 100);
            $table->string('kind', 24);
            $table->string('logical_name', 100);
            $table->unsignedBigInteger('resource_id');
            $table->timestamps();
            $table->unique(['project_id', 'environment_slug', 'kind', 'logical_name'], 'configuration_logical_owner_unique');
            $table->unique(['kind', 'resource_id'], 'configuration_resource_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_ownerships');
    }
};
