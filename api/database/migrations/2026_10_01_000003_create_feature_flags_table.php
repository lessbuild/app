<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5 part 5: feature flags operators turn on for everyone or for chosen accounts. */
return new class extends Migration
{
    /**
     * Create the feature flags table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('description', 255);
            $table->string('state', 10)->default('off');
            $table->json('account_ids')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(6);
        });
    }

    /**
     * Drop the feature flags table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
