<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_search_index', function (Blueprint $table) {
            $table->text('comment_content_ar')->nullable()->after('notes_en');
            $table->text('comment_content_en')->nullable()->after('comment_content_ar');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE task_search_index ADD COLUMN search_vector_comments_ar tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(comment_content_ar,''))) STORED");
            DB::statement("ALTER TABLE task_search_index ADD COLUMN search_vector_comments_en tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(comment_content_en,''))) STORED");
            DB::statement('CREATE INDEX task_search_index_comments_vector_ar_idx ON task_search_index USING GIN (search_vector_comments_ar)');
            DB::statement('CREATE INDEX task_search_index_comments_vector_en_idx ON task_search_index USING GIN (search_vector_comments_en)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS task_search_index_comments_vector_ar_idx');
            DB::statement('DROP INDEX IF EXISTS task_search_index_comments_vector_en_idx');
            DB::statement('ALTER TABLE task_search_index DROP COLUMN IF EXISTS search_vector_comments_ar');
            DB::statement('ALTER TABLE task_search_index DROP COLUMN IF EXISTS search_vector_comments_en');
        }

        Schema::table('task_search_index', function (Blueprint $table) {
            $table->dropColumn(['comment_content_ar', 'comment_content_en']);
        });
    }
};
