<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember each user's last choices on the upload form, also after their jobs are cleaned up.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('dub_options')->nullable();
        });
    }

    /**
     * Remove the column.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dub_options');
        });
    }
};
