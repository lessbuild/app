<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A daily export of a site's raw events to one of its project's storage buckets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_sites', function (Blueprint $table): void {
            $table->foreignId('export_bucket_id')->nullable()->constrained('storage_buckets')->nullOnDelete();
            $table->string('export_prefix', 200)->nullable();
            $table->date('exported_until')->nullable();
            $table->text('export_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('export_bucket_id');
            $table->dropColumn(['export_prefix', 'exported_until', 'export_error']);
        });
    }
};
