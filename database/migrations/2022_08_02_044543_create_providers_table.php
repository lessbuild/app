<?php

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
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class);
            $table->string('provider');
            $table->string('name');
            $table->string('token');
            $table->text('description');
            $table->timestamps();
            $table->softDeletes();
        });

        $user = User::find(1);
        $user->providers()->create([
            'provider' => 'digitalocean',
            'name' => 'DigitalOcean',
            'token' => 'dop_v1_2a1dadd1f88db242a1917e6b38a12dc549603a57da8ad1151142106bdcb54ffd',
            'description' => 'DigitalOcean',
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('providers');
    }
};
