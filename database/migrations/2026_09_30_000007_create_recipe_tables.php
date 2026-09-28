<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deploy part 7: account recipes with their revisions, and the gallery's favourites, ratings and reports. */
return new class extends Migration
{
    /**
     * Create the recipe tables.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('category', 20)->default('utilities');
            $table->longText('script');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at', 6)->nullable();
            $table->timestamp('gallery_revision_at', 6)->nullable();
            $table->foreignId('source_recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->timestamp('source_revision_at', 6)->nullable();
            $table->unsignedInteger('install_count')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps(6);
            $table->index(['account_id', 'name']);
            $table->index(['is_published', 'category']);
            $table->unique(['account_id', 'source_recipe_id']);
        });

        Schema::create('recipe_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('change', 16);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->longText('script');
            $table->timestamp('created_at', 6);
            $table->index(['recipe_id', 'id']);
        });

        Schema::create('recipe_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps(6);
            $table->unique(['recipe_id', 'user_id']);
        });

        Schema::create('recipe_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->timestamps(6);
            $table->unique(['recipe_id', 'user_id']);
        });

        Schema::create('recipe_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 16);
            $table->text('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->text('resolution_note')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['recipe_id', 'user_id']);
            $table->index(['recipe_id', 'status']);
        });
    }

    /**
     * Drop the recipe tables.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_reports');
        Schema::dropIfExists('recipe_ratings');
        Schema::dropIfExists('recipe_favorites');
        Schema::dropIfExists('recipe_revisions');
        Schema::dropIfExists('recipes');
    }
};
