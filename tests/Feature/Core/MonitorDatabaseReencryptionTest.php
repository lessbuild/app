<?php

namespace Tests\Feature\Core;

use App\Core\Services\Migration\ReencryptMonitorDatabaseValues;
use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\IngestPayload;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\MonitorCheck;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MonitorDatabaseReencryptionTest extends TestCase
{
    private Encrypter $sourceEncrypter;

    protected function setUp(): void
    {
        parent::setUp();

        $sourceKey = str_repeat('m', 32);
        $sourceAppKey = 'base64:'.base64_encode($sourceKey);
        config([
            'migration.source_app_keys.monitor' => $sourceAppKey,
            'migration.source_ciphers.monitor' => 'AES-256-CBC',
        ]);
        $this->sourceEncrypter = new Encrypter($sourceKey, 'AES-256-CBC');

        Schema::connection('monitor')->create('alert_destinations', function (Blueprint $table): void {
            $table->id();
            $table->text('endpoint_url')->nullable();
            $table->text('signing_secret')->nullable();
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->text('request_url')->nullable();
            $table->text('bearer_token')->nullable();
            $table->text('body_contains')->nullable();
            $table->text('hostname')->nullable();
            $table->text('dns_expected')->nullable();
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('monitor_checks', function (Blueprint $table): void {
            $table->id();
            $table->text('evidence')->nullable();
        });
        Schema::connection('monitor')->create('alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->text('payload')->nullable();
        });
        Schema::connection('monitor')->create('ingest_payloads', function (Blueprint $table): void {
            $table->unsignedBigInteger('ingest_receipt_id')->primary();
            $table->text('payload')->nullable();
        });

        $unknownKeyEncrypter = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');
        DB::connection('monitor')->table('alert_destinations')->insert([
            'id' => 1,
            'endpoint_url' => $this->sourceEncrypter->encryptString('https://hooks.example.test'),
        ]);
        DB::connection('monitor')->table('monitors')->insert([
            'id' => 1,
            'request_url' => $this->sourceEncrypter->encryptString('https://status.example.test/health'),
            'bearer_token' => $unknownKeyEncrypter->encryptString('unmapped-token'),
            'dns_expected' => $this->sourceEncrypter->encrypt(json_encode(['A' => ['192.0.2.1']], JSON_THROW_ON_ERROR), false),
        ]);
        DB::connection('monitor')->table('monitor_checks')->insert([
            'id' => 1,
            'evidence' => $this->sourceEncrypter->encrypt(json_encode(['response_excerpt' => 'healthy'], JSON_THROW_ON_ERROR), false),
        ]);
        DB::connection('monitor')->table('alert_deliveries')->insert([
            'id' => 1,
            'payload' => $this->sourceEncrypter->encrypt(json_encode(['event' => 'resolved'], JSON_THROW_ON_ERROR), false),
        ]);
        DB::connection('monitor')->table('ingest_payloads')->insert([
            'ingest_receipt_id' => 1,
            'payload' => $this->sourceEncrypter->encrypt(json_encode(['sample' => 'ok'], JSON_THROW_ON_ERROR), false),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['alert_destinations', 'monitors', 'monitor_checks', 'alert_deliveries', 'ingest_payloads'] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_apply_reencrypts_each_monitor_cast_idempotently_and_holds_unknown_ciphertext(): void
    {
        $service = app(ReencryptMonitorDatabaseValues::class);
        $oldMonitorUrl = DB::connection('monitor')->table('monitors')->value('request_url');

        $preview = $service->run();

        $this->assertSame(6, $preview['ready']);
        $this->assertSame(1, $preview['needs_review']);
        $this->assertSame(0, $preview['reencrypted']);
        $this->assertSame($oldMonitorUrl, DB::connection('monitor')->table('monitors')->value('request_url'));

        $applied = $service->run(apply: true);

        $this->assertSame(6, $applied['reencrypted']);
        $this->assertSame(1, $applied['needs_review']);
        $this->assertSame('https://status.example.test/health', Monitor::query()->findOrFail(1)->request_url);
        $this->assertSame(['A' => ['192.0.2.1']], Monitor::query()->findOrFail(1)->dns_expected);
        $this->assertSame('https://hooks.example.test', AlertDestination::query()->findOrFail(1)->endpoint_url);
        $this->assertSame(['response_excerpt' => 'healthy'], MonitorCheck::query()->findOrFail(1)->evidence);
        $this->assertSame(['event' => 'resolved'], AlertDelivery::query()->findOrFail(1)->payload);
        $this->assertSame(['sample' => 'ok'], IngestPayload::query()->findOrFail(1)->payload);
        $this->assertSame(
            'unmapped-token',
            (new Encrypter(str_repeat('x', 32), 'AES-256-CBC'))->decryptString(DB::connection('monitor')->table('monitors')->value('bearer_token')),
        );

        $repeated = $service->run(apply: true);

        $this->assertSame(0, $repeated['ready']);
        $this->assertSame(6, $repeated['already_current']);
        $this->assertSame(0, $repeated['reencrypted']);
        $this->assertSame(1, $repeated['needs_review']);
    }
}
