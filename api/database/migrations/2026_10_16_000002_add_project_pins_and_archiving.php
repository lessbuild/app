<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let projects be archived (out of the way, with everything kept), and let each person pin the projects they use
     * most to the top of their list.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('archived_at', 6)->nullable()->after('checklist_dismissed_at');
        });
        Schema::create('project_pins', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps(6);
            $table->unique(['user_id', 'project_id']);
        });
    }

    /**
     * Forget pins and archiving.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('project_pins');
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
