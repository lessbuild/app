<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Backups of the platform's own database: where each copy is and whether it worked. */
return new class extends Migration
{
    /**
     * Create the platform backups table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('platform_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('file', 120)->unique();
            $table->string('driver', 16);
            $table->string('status', 16);
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('remote_key', 500)->nullable();
            $table->text('error')->nullable();
            $table->string('trigger', 16)->default('schedule');
            $table->timestamp('uploaded_at', 6)->nullable();
            $table->timestamp('local_deleted_at', 6)->nullable();
            $table->timestamp('remote_deleted_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Drop the platform backups table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_backups');
    }
};
