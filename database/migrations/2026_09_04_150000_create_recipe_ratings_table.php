<?php

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Recipe::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->timestamps();
            $table->unique(['recipe_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ratings');
    }
};
