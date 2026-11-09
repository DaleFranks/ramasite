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
        Schema::table('modules', function (Blueprint $table) {
            $table->foreignId('background_image_id')->nullable()->after('style');
            $table->string('top_edge')->default('none')->after('background_image_id');
            $table->string('bottom_edge')->default('none')->after('top_edge');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['background_image_id', 'top_edge', 'bottom_edge']);
        });
    }
};
