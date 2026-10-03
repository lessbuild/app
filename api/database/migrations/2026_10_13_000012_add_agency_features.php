<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add agency features: the account's own branding for what clients see (status pages, shared reports, client
     * reports), and clients with their projects, cost markup and monthly report.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('brand_name', 100)->nullable();
            $table->string('brand_logo_url', 500)->nullable();
            $table->string('brand_color', 7)->nullable();
        });
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('emails');
            $table->json('project_ids');
            $table->unsignedSmallInteger('markup_percent')->default(0);
            $table->boolean('monthly_report')->default(true);
            $table->string('last_report_month', 7)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Remove them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['brand_name', 'brand_logo_url', 'brand_color']);
        });
    }
};
