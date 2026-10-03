<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notes on a site's chart, such as "Launched on Product Hunt", shown on the day they're for. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_annotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->string('text', 200);
            $table->timestamps();
            $table->index(['site_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_annotations');
    }
};
