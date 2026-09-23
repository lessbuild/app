<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 24);
            $table->string('match_type', 24)->default('exact');
            $table->string('match_value', 255);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['site_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('goals');
    }
};
