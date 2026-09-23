<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('websites')
            ->select(['id', 'environment'])
            ->orderBy('id')
            ->each(function (object $website): void {
                DB::table('websites')->where('id', $website->id)->update([
                    'environment' => Crypt::encryptString($website->environment),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('websites')
            ->select(['id', 'environment'])
            ->orderBy('id')
            ->each(function (object $website): void {
                DB::table('websites')->where('id', $website->id)->update([
                    'environment' => Crypt::decryptString($website->environment),
                ]);
            });
    }
};
