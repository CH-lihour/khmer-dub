<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember how far the current step is: seconds of the video heard, or lines translated or voiced.
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->unsignedInteger('step_done')->nullable()->after('message');
            $table->unsignedInteger('step_total')->nullable()->after('step_done');
        });
    }

    /**
     * Remove the columns.
     */
    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['step_done', 'step_total']);
        });
    }
};
