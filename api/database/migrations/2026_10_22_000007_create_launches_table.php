<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Launches: setting up a whole repository (server, websites, environments, repositories) and its first deploys. */
return new class extends Migration
{
    /**
     * Create the table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('launches', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->string('step', 30)->nullable();
            $table->json('plan');
            $table->json('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop it again.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('launches');
    }
};
