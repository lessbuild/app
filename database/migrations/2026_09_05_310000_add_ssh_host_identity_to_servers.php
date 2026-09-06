<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->text('ssh_host_key')->nullable()->after('ssh_private_key');
            $table->string('ssh_host_fingerprint', 100)->nullable()->after('ssh_host_key');
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn(['ssh_host_key', 'ssh_host_fingerprint']));
    }
};
