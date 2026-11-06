<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember how loud the original sound plays under the Khmer voice, from 0 (off) to 1.
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->decimal('original_volume', 3, 2)->default(0)->after('review');
        });
    }

    /**
     * Remove the column.
     */
    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn('original_volume');
        });
    }
};
