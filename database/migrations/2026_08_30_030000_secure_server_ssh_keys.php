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
        Schema::table('servers', function (Blueprint $table) {
            $table->text('ssh_public_key')->nullable()->after('ssh_fingerprint');
            $table->text('ssh_private_key')->nullable()->after('ssh_public_key');
        });

        DB::table('servers')
            ->select(['id', 'keypair'])
            ->whereNotNull('keypair')
            ->orderBy('id')
            ->each(function (object $server): void {
                $keyPair = is_string($server->keypair)
                    ? json_decode($server->keypair, true)
                    : (array) $server->keypair;

                if (! is_array($keyPair) || ! isset($keyPair['public'], $keyPair['private'])) {
                    throw new RuntimeException("Server {$server->id} has an invalid legacy SSH key pair.");
                }

                DB::table('servers')->where('id', $server->id)->update([
                    'ssh_public_key' => $keyPair['public'],
                    'ssh_private_key' => Crypt::encryptString($keyPair['private']),
                ]);
            });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('keypair');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->json('keypair')->nullable();
        });

        DB::table('servers')
            ->select(['id', 'ssh_public_key', 'ssh_private_key'])
            ->whereNotNull('ssh_private_key')
            ->orderBy('id')
            ->each(function (object $server): void {
                DB::table('servers')->where('id', $server->id)->update([
                    'keypair' => json_encode([
                        'public' => $server->ssh_public_key,
                        'private' => Crypt::decryptString($server->ssh_private_key),
                    ], JSON_THROW_ON_ERROR),
                ]);
            });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['ssh_public_key', 'ssh_private_key']);
        });
    }
};
