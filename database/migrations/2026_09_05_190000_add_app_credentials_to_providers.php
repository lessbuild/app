<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->string('credential_type')->default('token')->after('provider');
            $table->string('external_id')->nullable()->after('credential_type');
            $table->unique(['organization_id', 'provider', 'credential_type', 'external_id'], 'providers_external_credential_unique');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->dropUnique('providers_external_credential_unique');
            $table->dropColumn(['credential_type', 'external_id']);
        });
    }
};
