<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('configuration_application_id')->constrained()->cascadeOnDelete();
            $table->string('environment_slug', 100);
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('build_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->string('status', 32)->default('pending');
            $table->longText('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['configuration_application_id', 'environment_slug', 'kind'], 'configuration_operation_identity_unique');
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_operations');
    }
};
