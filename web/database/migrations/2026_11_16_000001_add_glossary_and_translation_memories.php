<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each user's glossary (terms to keep or to put in a given Khmer) and the Khmer they wrote for
     * lines when reviewing, to reuse for the same line in later videos.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('glossary')->nullable()->after('dub_options');
        });

        Schema::create('translation_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language', 8); // the spoken language, a Whisper code
            $table->text('source'); // the original line
            $table->char('source_key', 40); // sha1 of the line without case and extra spaces, to find it again
            $table->text('km');
            $table->timestamps();
            $table->unique(['user_id', 'language', 'source_key']);
        });
    }

    /**
     * Remove the column and the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('translation_memories');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('glossary');
        });
    }
};
