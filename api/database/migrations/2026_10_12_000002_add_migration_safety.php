<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add migration safety checks: the environment setting, the destructive statements a deploy found, and the
     * approvals that let a revision's migrations run.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->boolean('migration_safety')->default(false);
        });
        Schema::table('builds', function (Blueprint $table): void {
            $table->text('destructive_migrations')->nullable();
        });
        Schema::create('migration_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->string('revision', 64);
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('statements');
            $table->timestamps();
            $table->unique(['environment_id', 'revision']);
        });
    }

    /**
     * Remove them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('migration_approvals');
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropColumn('destructive_migrations');
        });
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('migration_safety');
        });
    }
};
