<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Imports of a site's history from Google Analytics into its daily totals, and the last day imported. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16)->default('ga4');
            $table->text('refresh_token')->nullable();
            $table->string('property', 32)->nullable();
            $table->string('property_name')->nullable();
            $table->string('status', 16);
            $table->date('from_date')->nullable();
            $table->date('until_date')->nullable();
            $table->unsignedInteger('days_imported')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::table('analytics_sites', function (Blueprint $table): void {
            $table->date('imported_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_sites', fn (Blueprint $table) => $table->dropColumn('imported_until'));
        Schema::dropIfExists('analytics_imports');
    }
};
