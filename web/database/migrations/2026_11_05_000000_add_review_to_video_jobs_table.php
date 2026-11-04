<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember whether a person wants to edit the Khmer before the results are made.
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->boolean('review')->default(false)->after('dub');
        });
    }

    /**
     * Remove the column.
     */
    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn('review');
        });
    }
};
