<?php

namespace Tests\Feature\Core;

use App\Core\Services\Migration\ReencryptDeployerDatabaseValues;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DeployerDatabaseReencryptionTest extends TestCase
{
    private Encrypter $sourceEncrypter;

    private Encrypter $unknownEncrypter;

    private Encrypter $targetEncrypter;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.deployer.driver' => 'sqlite',
            'database.connections.deployer.database' => ':memory:',
        ]);
        DB::purge('deployer');

        $sourceKey = str_repeat('d', 32);
        $targetKey = str_repeat('u', 32);
        $unknownKey = str_repeat('x', 32);
        config([
            'migration.source_app_keys.deployer' => 'base64:'.base64_encode($sourceKey),
            'migration.source_ciphers.deployer' => 'AES-256-CBC',
            'app.key' => 'base64:'.base64_encode($targetKey),
            'app.cipher' => 'AES-256-CBC',
        ]);
        $this->sourceEncrypter = new Encrypter($sourceKey, 'AES-256-CBC');
        $this->unknownEncrypter = new Encrypter($unknownKey, 'AES-256-CBC');
        $this->targetEncrypter = new Encrypter($targetKey, 'AES-256-CBC');

        Schema::connection('deployer')->create('providers', function (Blueprint $table): void {
            $table->id();
            $table->text('token')->nullable();
        });
        Schema::connection('deployer')->create('servers', function (Blueprint $table): void {
            $table->id();
            $table->text('password')->nullable();
            $table->text('mysql_root_password')->nullable();
            $table->text('ssh_private_key')->nullable();
            $table->text('ssh_host_key')->nullable();
            $table->text('recipe_snapshot')->nullable();
        });

        DB::connection('deployer')->table('providers')->insert([
            'id' => 1,
            'token' => $this->sourceEncrypter->encryptString('provider-token'),
        ]);
        DB::connection('deployer')->table('servers')->insert([
            'id' => 1,
            'password' => $this->sourceEncrypter->encryptString('server-password'),
            'mysql_root_password' => $this->sourceEncrypter->encryptString('root-password'),
            'ssh_private_key' => $this->sourceEncrypter->encryptString('private-key'),
            'ssh_host_key' => $this->unknownEncrypter->encryptString('unknown-host-key'),
            'recipe_snapshot' => $this->sourceEncrypter->encrypt(json_encode(['recipes' => ['safe']], JSON_THROW_ON_ERROR), false),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::connection('deployer')->dropIfExists('providers');
        Schema::connection('deployer')->dropIfExists('servers');
        DB::purge('deployer');

        parent::tearDown();
    }

    public function test_apply_reencrypts_model_casts_idempotently_and_holds_unknown_ciphertext_without_partial_writes(): void
    {
        $service = app(ReencryptDeployerDatabaseValues::class);
        $originalProviderToken = DB::connection('deployer')->table('providers')->value('token');

        $preview = $service->run();

        $this->assertSame(5, $preview['ready']);
        $this->assertSame(1, $preview['needs_review']);
        $this->assertSame(0, $preview['reencrypted']);
        $this->assertSame($originalProviderToken, DB::connection('deployer')->table('providers')->value('token'));

        $blockedApply = $service->run(apply: true);

        $this->assertSame(1, $blockedApply['needs_review']);
        $this->assertSame(0, $blockedApply['reencrypted']);
        $this->assertSame($originalProviderToken, DB::connection('deployer')->table('providers')->value('token'));

        DB::connection('deployer')->table('servers')->where('id', 1)->update([
            'ssh_host_key' => $this->sourceEncrypter->encryptString('host-key'),
        ]);

        $applied = $service->run(apply: true);

        $this->assertSame(6, $applied['reencrypted']);
        $this->assertSame(0, $applied['needs_review']);
        $this->assertSame('provider-token', $this->targetEncrypter->decryptString(DB::connection('deployer')->table('providers')->value('token')));
        $this->assertSame('private-key', $this->targetEncrypter->decryptString(DB::connection('deployer')->table('servers')->value('ssh_private_key')));
        $this->assertSame(
            ['recipes' => ['safe']],
            json_decode($this->targetEncrypter->decrypt(DB::connection('deployer')->table('servers')->value('recipe_snapshot'), false), true, flags: JSON_THROW_ON_ERROR),
        );

        $repeated = $service->run(apply: true);

        $this->assertSame(0, $repeated['ready']);
        $this->assertSame(6, $repeated['already_current']);
        $this->assertSame(0, $repeated['reencrypted']);
    }
}
