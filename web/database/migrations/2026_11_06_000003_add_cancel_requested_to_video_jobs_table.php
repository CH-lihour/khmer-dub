<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember that a person asked to stop a job, until the worker has stopped it.
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->boolean('cancel_requested')->default(false)->after('error');
        });
    }

    /**
     * Remove the column.
     */
    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn('cancel_requested');
        });
    }
};
