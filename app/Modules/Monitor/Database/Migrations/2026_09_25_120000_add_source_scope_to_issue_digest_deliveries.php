<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->table('issue_digest_deliveries', function (Blueprint $table): void {
            $table->json('source_scope')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->table('issue_digest_deliveries', function (Blueprint $table): void {
            $table->dropColumn('source_scope');
        });
    }
};
