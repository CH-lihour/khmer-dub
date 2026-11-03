<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that tracks every uploaded video and its dubbing job.
     */
    public function up(): void
    {
        Schema::create('video_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // filled once login exists
            $table->string('original_name');
            $table->string('path');
            $table->string('gender')->default('female');
            $table->boolean('dub')->default(true);
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message')->nullable();
            $table->string('language')->nullable();
            $table->string('worker_job_id')->nullable();
            $table->string('srt_path')->nullable();
            $table->string('video_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_jobs');
    }
};
