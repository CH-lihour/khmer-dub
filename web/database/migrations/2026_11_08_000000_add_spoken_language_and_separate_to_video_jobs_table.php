<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The spoken language a person chose on the form (null: detect it), and whether to take the
     * original speech out of the sound under the Khmer voice.
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->string('spoken_language', 8)->nullable()->after('original_volume');
            $table->boolean('separate')->default(false)->after('spoken_language');
        });
    }

    /**
     * Remove the columns.
     */
    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['spoken_language', 'separate']);
        });
    }
};
