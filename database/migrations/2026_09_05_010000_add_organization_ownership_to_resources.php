<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['providers', 'servers', 'websites', 'repositories', 'recipes'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
                $blueprint->index(['organization_id', 'created_at']);
            });

            DB::table($table)->whereNull('organization_id')->update([
                'organization_id' => DB::raw('(SELECT current_organization_id FROM users WHERE users.id = '.$table.'.user_id)'),
            ]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['organization_id', 'created_at']);
                $blueprint->dropConstrainedForeignId('organization_id');
            });
        }
    }
};
