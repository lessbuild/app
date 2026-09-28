<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Named filters people save on list pages (the audit log, notifications, Monitoring's events, issues and traces). */
return new class extends Migration
{
    /**
     * Create the saved views table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('page', 64);
            $table->string('name', 60);
            $table->json('parameters');
            $table->json('query');
            $table->timestamps(6);
            $table->unique(['user_id', 'page', 'account_id', 'name']);
        });
    }

    /**
     * Drop the saved views table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
