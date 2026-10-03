<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Accounts can use SAML 2.0 for single sign-on instead of OpenID Connect. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('sso_protocol', 8)->default('oidc');
            $table->string('saml_idp_entity_id', 500)->nullable();
            $table->string('saml_idp_sso_url', 500)->nullable();
            $table->text('saml_idp_certificate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['sso_protocol', 'saml_idp_entity_id', 'saml_idp_sso_url', 'saml_idp_certificate']);
        });
    }
};
