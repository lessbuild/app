<?php

use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class);
            $table->foreignIdFor(Provider::class)->nullable();
            $table->integer('identifier')->nullable();
            $table->string('name')->nullable();
            $table->string('region')->nullable();
            $table->string('image')->nullable();
            $table->string('size')->nullable();
            $table->string('ssh_fingerprint')->nullable();
            $table->integer('setup_stage')->default(0);
            $table->string('password')->nullable();
            $table->boolean('setup')->default(false);
            $table->string('public_ip')->nullable();
            $table->string('private_ip')->nullable();
            $table->json('keypair')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('servers');
    }
};
