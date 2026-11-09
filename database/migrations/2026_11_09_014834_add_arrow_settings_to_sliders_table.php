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
        Schema::table('sliders', function (Blueprint $table) {
            $table->string('arrow_style')->default('square')->after('edge_to_edge');
            $table->string('arrow_color')->nullable()->after('arrow_style');
            $table->string('arrow_background_color')->nullable()->after('arrow_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sliders', function (Blueprint $table) {
            $table->dropColumn(['arrow_style', 'arrow_color', 'arrow_background_color']);
        });
    }
};
