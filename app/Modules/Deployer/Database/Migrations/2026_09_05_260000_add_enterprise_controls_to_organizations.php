<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->text('allowed_ip_ranges')->nullable();
            $table->json('allowed_email_domains')->nullable();
            $table->boolean('require_two_factor')->default(false);
            $table->unsignedSmallInteger('session_idle_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['allowed_ip_ranges', 'allowed_email_domains', 'require_two_factor', 'session_idle_minutes']));
    }
};
