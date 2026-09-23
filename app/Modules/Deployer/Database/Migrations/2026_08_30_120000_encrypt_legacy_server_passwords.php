<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->text('password')->nullable()->change();
        });

        DB::table('servers')
            ->select(['id', 'password'])
            ->whereNotNull('password')
            ->orderBy('id')
            ->each(function (object $server): void {
                DB::table('servers')->where('id', $server->id)->update([
                    'password' => Crypt::encryptString($server->password),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('servers')
            ->select(['id', 'password'])
            ->whereNotNull('password')
            ->orderBy('id')
            ->each(function (object $server): void {
                DB::table('servers')->where('id', $server->id)->update([
                    'password' => Crypt::decryptString($server->password),
                ]);
            });

        Schema::table('servers', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }
};
