<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Account-wide security rules (two-factor, email domains, IP ranges, idle sign-out) and single sign-on through OIDC. */
return new class extends Migration
{
    /**
     * Add the security rule and single sign-on columns.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('require_two_factor')->default(false);
            $table->json('allowed_email_domains')->nullable();
            $table->json('allowed_ip_ranges')->nullable();
            $table->unsignedSmallInteger('session_idle_minutes')->nullable();
            $table->string('sso_issuer', 500)->nullable();
            $table->string('sso_client_id', 255)->nullable();
            $table->text('sso_client_secret')->nullable();
            $table->boolean('sso_enforced')->default(false);
        });
    }

    /**
     * Drop the security rule and single sign-on columns.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['require_two_factor', 'allowed_email_domains', 'allowed_ip_ranges', 'session_idle_minutes', 'sso_issuer', 'sso_client_id', 'sso_client_secret', 'sso_enforced']);
        });
    }
};
