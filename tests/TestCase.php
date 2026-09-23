<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        if (app()->environment('testing')
            && Schema::hasTable('builds')
            && ! Schema::hasTable('deployment_succeeded_outbox_events')) {
            Schema::create('deployment_succeeded_outbox_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('event_type', 100);
                $table->unsignedSmallInteger('event_version')->default(1);
                $table->unsignedBigInteger('source_build_id');
                $table->unsignedBigInteger('source_project_id');
                $table->unsignedBigInteger('source_environment_id');
                $table->json('payload');
                $table->string('status', 24)->default('pending');
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->string('last_error_code', 100)->nullable();
                $table->timestamp('last_error_at')->nullable();
                $table->timestamps();
                $table->unique(['event_type', 'source_build_id']);
            });
        }

        if (str_starts_with(static::class, 'Tests\\Modules\\Analytics\\')) {
            URL::forceRootUrl('http://analytics.test');
        }
    }
}
