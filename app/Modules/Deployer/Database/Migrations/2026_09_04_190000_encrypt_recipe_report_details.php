<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('recipe_reports')
            ->whereNotNull('details')
            ->select(['id', 'details'])
            ->orderBy('id')
            ->each(function (object $report): void {
                DB::table('recipe_reports')->where('id', $report->id)->update([
                    'details' => Crypt::encryptString($report->details),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('recipe_reports')
            ->whereNotNull('details')
            ->select(['id', 'details'])
            ->orderBy('id')
            ->each(function (object $report): void {
                DB::table('recipe_reports')->where('id', $report->id)->update([
                    'details' => Crypt::decryptString($report->details),
                ]);
            });
    }
};
