<?php

use App\Models\Provider;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Website::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'created_by')->constrained('users')->restrictOnDelete();
            $table->foreignIdFor(Provider::class, 'dns_provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->string('hostname')->unique();
            $table->string('type', 16)->default('alias');
            $table->string('redirect_url')->nullable();
            $table->boolean('is_temporary')->default(false);
            $table->string('dns_record_id')->nullable();
            $table->string('dns_status', 20)->default('pending');
            $table->string('ssl_status', 20)->default('pending');
            $table->timestamp('certificate_expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'type']);
            $table->index(['ssl_status', 'certificate_expires_at']);
        });

        $now = now();
        DB::table('websites')->whereNull('deleted_at')->orderBy('id')->each(function ($website) use ($now): void {
            DB::table('website_domains')->insertOrIgnore([
                'website_id' => $website->id,
                'created_by' => $website->user_id,
                'hostname' => $website->url,
                'type' => 'primary',
                'dns_status' => 'active',
                'ssl_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_domains');
    }
};
