<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'slug']);
        });

        Schema::create('environments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('slug', 70);
            $table->string('kind', 16);
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
        });

        Schema::create('project_services', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('service', 32);
            $table->foreignUlid('enabled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'service']);
        });

        Schema::table('memberships', function (Blueprint $table): void {
            // Null means every service; otherwise the service keys this member may use.
            $table->json('service_access')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $table) => $table->dropColumn('service_access'));
        Schema::dropIfExists('project_services');
        Schema::dropIfExists('environments');
        Schema::dropIfExists('projects');
    }
};
