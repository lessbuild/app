<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of each server's latest disk scan: what's taking space that can be cleared.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('server_disk_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued');
            $table->json('findings')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('last_cleaned', 30)->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop it.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('server_disk_scans');
    }
};
