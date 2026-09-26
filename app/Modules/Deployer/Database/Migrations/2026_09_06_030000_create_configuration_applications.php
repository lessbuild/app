<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('configuration_review_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->timestamp('locally_applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_applications');
    }
};
