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
        Schema::table('providers', function (Blueprint $table) {
            $table->text('token')->change();
        });

        DB::table('providers')->orderBy('id')->eachById(function ($provider) {
            DB::table('providers')
                ->where('id', $provider->id)
                ->update(['token' => Crypt::encryptString($provider->token)]);
        });
    }

    public function down(): void
    {
        DB::table('providers')->orderBy('id')->eachById(function ($provider) {
            DB::table('providers')
                ->where('id', $provider->id)
                ->update(['token' => Crypt::decryptString($provider->token)]);
        });
    }
};
