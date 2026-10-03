<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Feedback people send from inside the app, for platform admins to read and resolve. */
return new class extends Migration
{
    /**
     * Create the feedback table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('kind', 16);
            $table->text('message');
            $table->string('page', 2048)->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['resolved_at', 'created_at']);
        });
    }

    /**
     * Drop the feedback table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
