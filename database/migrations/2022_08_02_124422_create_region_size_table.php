<?php

use App\Actions\GenerateSizesAndRegionsAction;
use App\Models\Region;
use App\Models\Size;
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
        Schema::create('region_size', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Region::class);
            $table->foreignIdFor(Size::class);
            $table->timestamps();
        });

        (new GenerateSizesAndRegionsAction())->handle();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
//        Schema::dropIfExists('region_size');
    }
};
