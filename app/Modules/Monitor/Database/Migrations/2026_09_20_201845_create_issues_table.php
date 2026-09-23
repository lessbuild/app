<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->char('fingerprint', 64);
            $table->string('type', 32)->default('exception');
            $table->string('severity', 16)->default('error');
            $table->string('status', 16)->default('open');
            $table->string('title');
            $table->string('location')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedInteger('affected_users')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->text('details')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'fingerprint']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('issues');
    }
};
