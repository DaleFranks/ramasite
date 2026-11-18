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
        Schema::create('social_link_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_style')->default('dark');
            $table->string('logo_color', 7)->nullable();
            $table->unsignedSmallInteger('logo_size')->default(32);
            $table->boolean('opens_in_new_tab')->default(true);
            $table->json('links');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_link_sets');
    }
};
