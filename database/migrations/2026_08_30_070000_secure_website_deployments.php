<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->text('mysql_root_password')->nullable()->after('ssh_private_key');
        });
        Schema::table('websites', function (Blueprint $table): void {
            $table->string('deployment_slug', 32)->nullable()->after('name');
        });

        $used = [];
        DB::table('websites')->orderBy('id')->each(function (object $website) use (&$used): void {
            $base = substr(Str::slug($website->name) ?: 'website', 0, 32);
            $slug = $base;
            $suffix = 2;
            $key = $website->user_id.':'.$slug;

            while (isset($used[$key])) {
                $ending = '-'.$suffix++;
                $slug = substr($base, 0, 32 - strlen($ending)).$ending;
                $key = $website->user_id.':'.$slug;
            }

            $used[$key] = true;
            DB::table('websites')->where('id', $website->id)->update(['deployment_slug' => $slug]);
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->string('deployment_slug', 32)->nullable(false)->change();
            $table->unique(['user_id', 'deployment_slug']);
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'deployment_slug']);
            $table->dropColumn('deployment_slug');
        });
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('mysql_root_password');
        });
    }
};
