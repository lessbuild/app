<?php

use App\Models\Website;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_health_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Website::class)->constrained()->cascadeOnDelete();
            $table->boolean('successful');
            $table->string('source', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('endpoint', 512);
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at');
            $table->index(['website_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_health_checks');
    }
};
