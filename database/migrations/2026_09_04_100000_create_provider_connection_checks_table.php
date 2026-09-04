<?php

use App\Models\Provider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connection_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Provider::class)->constrained()->cascadeOnDelete();
            $table->boolean('successful');
            $table->string('source', 16);
            $table->string('provider_type', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('endpoint', 512)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at');
            $table->index(['provider_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connection_checks');
    }
};
