<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A module's background image is protected by the
     * database, as a slide's image is: an image in use can't be deleted.
     * Backgrounds that already point to a missing image are cleared first,
     * so the constraint can be added.
     */
    public function up(): void
    {
        DB::table('modules')
            ->whereNotNull('background_image_id')
            ->whereNotIn('background_image_id', DB::table('images')->select('id'))
            ->update(['background_image_id' => null]);

        Schema::table('modules', function (Blueprint $table) {
            $table->foreign('background_image_id')->references('id')->on('images')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropForeign(['background_image_id']);
        });
    }
};
