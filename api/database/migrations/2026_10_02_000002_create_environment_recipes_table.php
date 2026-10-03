<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Environment recipes: ordered snapshots of library recipes that run on an environment's servers. */
return new class extends Migration
{
    /**
     * Create the environment recipes table and the run-on-new-websites setting.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->boolean('recipes_run_on_new_websites')->default(false);
        });

        Schema::create('environment_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 120);
            $table->longText('script');
            $table->timestamp('source_updated_at', 6)->nullable();
            $table->foreignUlid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(6);
            $table->unique(['environment_id', 'recipe_id']);
            $table->index(['environment_id', 'position']);
        });
    }

    /**
     * Drop them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('environment_recipes');
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn('recipes_run_on_new_websites'));
    }
};
