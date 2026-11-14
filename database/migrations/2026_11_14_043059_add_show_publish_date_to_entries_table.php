<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Each page and post can show or hide its publish date;
     * empty means its type decides (posts show it, pages and blogs don't).
     *
     * Every published entry now has a publish date. Those published straight
     * away before this had none, and get the day they were created.
     */
    public function up(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->boolean('show_publish_date')->nullable()->after('published_at');
        });

        DB::table('entries')
            ->where('status', 'published')
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations. The filled-in publish dates are kept: they can't
     * be told apart from dates entered by hand.
     */
    public function down(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->dropColumn('show_publish_date');
        });
    }
};
