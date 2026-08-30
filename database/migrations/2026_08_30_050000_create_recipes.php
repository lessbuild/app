<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('script');
            $table->timestamps();
        });

        Schema::create('recipe_server', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->primary(['recipe_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_server');
        Schema::dropIfExists('recipes');
    }
};
