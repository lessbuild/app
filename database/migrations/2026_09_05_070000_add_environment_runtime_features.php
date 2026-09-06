<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('preset', 32)->default('laravel');
        });
        Schema::table('builds', function (Blueprint $table): void {
            $table->longText('environment_payload')->nullable();
        });
        Schema::create('environment_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->text('command');
            $table->unsignedSmallInteger('replicas')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->unique(['environment_id', 'name']);
        });
        Schema::create('environment_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 24);
            $table->boolean('is_managed')->default(false);
            $table->text('configuration')->nullable();
            $table->string('status', 20)->default('ready');
            $table->timestamps();
            $table->unique(['environment_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_resources');
        Schema::dropIfExists('environment_processes');
        Schema::table('builds', fn (Blueprint $table) => $table->dropColumn('environment_payload'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('preset'));
    }
};
