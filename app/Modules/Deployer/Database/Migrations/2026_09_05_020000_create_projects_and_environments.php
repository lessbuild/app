<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('environments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type', 20);
            $table->string('branch')->default('main');
            $table->boolean('is_protected')->default(false);
            $table->boolean('requires_deployment_approval')->default(false);
            $table->unsignedSmallInteger('minimum_replicas')->default(1);
            $table->unsignedSmallInteger('maximum_replicas')->default(1);
            $table->unsignedSmallInteger('hibernate_after_minutes')->nullable();
            $table->string('status', 20)->default('ready');
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
        });

        Schema::create('environment_variables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->string('key');
            $table->text('value');
            $table->boolean('is_secret')->default(true);
            $table->timestamps();
            $table->unique(['environment_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_variables');
        Schema::dropIfExists('environments');
        Schema::dropIfExists('projects');
    }
};
