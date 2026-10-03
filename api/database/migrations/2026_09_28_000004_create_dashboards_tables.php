<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved Monitoring dashboards: an account's chosen widgets over one time range. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('range', 8)->default('24h');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps(6);
            $table->index(['account_id', 'name', 'id'], 'dashboards_account_name_index');
        });

        Schema::create('dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedSmallInteger('position');
            $table->json('configuration')->nullable();
            $table->timestamps(6);
            $table->unique(['dashboard_id', 'position'], 'dashboard_widgets_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
    }
};
