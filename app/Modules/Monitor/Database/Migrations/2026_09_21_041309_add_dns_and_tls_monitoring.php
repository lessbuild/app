<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->table('monitors', function (Blueprint $table): void {
            $table->text('request_url')->nullable()->change();
            $table->text('hostname')->nullable();
            $table->string('dns_record_type', 5)->nullable();
            $table->string('dns_match', 10)->nullable();
            $table->text('dns_expected')->nullable();
            $table->unsignedSmallInteger('tls_port')->nullable();
            $table->unsignedTinyInteger('tls_expiry_days')->nullable();
        });
        Schema::connection('monitor')->table('monitor_checks', function (Blueprint $table): void {
            $table->json('details')->nullable();
            $table->longText('evidence')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::connection('monitor')->table('monitors')->where('type', '!=', 'http')->orWhereNull('request_url')->exists()
            || DB::connection('monitor')->table('monitor_checks')->whereNotNull('evidence')->orWhereNotNull('details')->exists()) {
            throw new RuntimeException('DNS or TLS monitoring history exists. Use a forward migration to preserve it.');
        }
        Schema::connection('monitor')->table('monitor_checks', function (Blueprint $table): void {
            $table->dropColumn(['details', 'evidence']);
        });
        Schema::connection('monitor')->table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['hostname', 'dns_record_type', 'dns_match', 'dns_expected', 'tls_port', 'tls_expiry_days']);
            $table->text('request_url')->nullable(false)->change();
        });
    }
};
