<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        if (! Schema::connection('core')->hasColumn('workspace_product_access', 'metadata')) {
            Schema::connection('core')->table('workspace_product_access', function (Blueprint $table): void {
                $table->json('metadata')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('core')->hasColumn('workspace_product_access', 'metadata')) {
            Schema::connection('core')->table('workspace_product_access', function (Blueprint $table): void {
                $table->dropColumn('metadata');
            });
        }
    }
};
