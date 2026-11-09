<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->string('caption_position')->default('bottom')->after('caption');
            $table->string('caption_text_color')->nullable()->after('caption_position');
            $table->string('caption_background_color')->nullable()->after('caption_text_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->dropColumn(['caption_position', 'caption_text_color', 'caption_background_color']);
        });
    }
};
