<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of servers that send their logs to a Monitoring environment.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('server_log_shipping', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingest_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('installing');
            $table->string('last_error', 500)->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop the table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('server_log_shipping');
    }
};
