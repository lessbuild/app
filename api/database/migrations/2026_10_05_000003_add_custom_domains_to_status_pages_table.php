<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A status page can be served on the customer's own hostname once a TXT record proves they control it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->string('custom_domain', 253)->nullable()->unique();
            $table->string('custom_domain_token', 64)->nullable();
            $table->timestamp('custom_domain_verified_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn(['custom_domain', 'custom_domain_token', 'custom_domain_verified_at']);
        });
    }
};
