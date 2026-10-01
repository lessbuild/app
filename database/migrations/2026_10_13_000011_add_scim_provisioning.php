<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add SCIM provisioning: the account's hashed SCIM token and the role new people get, and the people the identity
     * provider manages (kept when deactivated, so they can be reactivated).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('scim_token_hash', 64)->nullable()->unique();
            $table->string('scim_default_role', 20)->default('member');
        });
        Schema::create('scim_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['account_id', 'user_id']);
        });
    }

    /**
     * Remove it.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('scim_users');
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['scim_token_hash']);
            $table->dropColumn(['scim_token_hash', 'scim_default_role']);
        });
    }
};
