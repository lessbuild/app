<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloudflare edge settings for a domain whose DNS BuildPusher manages: serving it through Cloudflare's CDN, blocking
 * countries and addresses, and a per-visitor rate limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_domains', function (Blueprint $table): void {
            $table->boolean('cdn_proxied')->default(false);
            $table->json('blocked_countries')->nullable();
            $table->json('blocked_ips')->nullable();
            $table->unsignedSmallInteger('rate_limit_requests')->nullable();
            $table->text('edge_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_domains', fn (Blueprint $table) => $table->dropColumn(['cdn_proxied', 'blocked_countries', 'blocked_ips', 'rate_limit_requests', 'edge_error']));
    }
};
