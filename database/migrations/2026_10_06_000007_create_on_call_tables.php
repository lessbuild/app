<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** On-call rotations: who's on call when, temporary overrides, and email alert destinations that follow a rotation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('on_call_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('timezone', 64);
            $table->string('rotation', 16);
            $table->string('handoff_time', 5);
            $table->unsignedTinyInteger('handoff_day')->nullable();
            $table->date('starts_on');
            $table->timestamps();
        });

        Schema::create('on_call_schedule_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('on_call_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->index(['on_call_schedule_id', 'position']);
        });

        Schema::create('on_call_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('on_call_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['on_call_schedule_id', 'ends_at']);
        });

        Schema::table('alert_destinations', function (Blueprint $table): void {
            $table->foreignId('on_call_schedule_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alert_destinations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('on_call_schedule_id');
        });
        Schema::dropIfExists('on_call_overrides');
        Schema::dropIfExists('on_call_schedule_members');
        Schema::dropIfExists('on_call_schedules');
    }
};
