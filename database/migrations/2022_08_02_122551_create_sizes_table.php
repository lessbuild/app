<?php

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
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->text('description');
            $table->decimal('memory');
            $table->decimal('vcpus');
            $table->decimal('disk');
            $table->decimal('transfer');
            $table->decimal('price_monthly');
            $table->decimal('price_hourly');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
//        Schema::dropIfExists('sizes');
    }
};
