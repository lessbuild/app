<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of templates saved from an account's own projects.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('project_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('description', 500);
            $table->json('definition');
            $table->timestamps();
        });
    }

    /**
     * Drop it.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('project_templates');
    }
};
