<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Two-person approval for an environment's variables: a change waits until someone else approves it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->boolean('require_variable_approval')->default(false);
        });

        Schema::create('pending_variable_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->text('payload');
            $table->string('summary', 255);
            $table->string('status', 16);
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['environment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_variable_changes');
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('require_variable_approval');
        });
    }
};
