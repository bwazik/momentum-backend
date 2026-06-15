<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tasks ADD COLUMN search_vector_ar tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(title_ar,'') || ' ' || coalesce(description_ar,''))) STORED");
            DB::statement("ALTER TABLE tasks ADD COLUMN search_vector_en tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(title_en,'') || ' ' || coalesce(description_en,''))) STORED");

            DB::statement('CREATE INDEX tasks_search_vector_ar_idx ON tasks USING GIN (search_vector_ar)');
            DB::statement('CREATE INDEX tasks_search_vector_en_idx ON tasks USING GIN (search_vector_en)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS tasks_search_vector_ar_idx');
            DB::statement('DROP INDEX IF EXISTS tasks_search_vector_en_idx');
            Schema::table('tasks', function ($table) {
                $table->dropColumn(['search_vector_ar', 'search_vector_en']);
            });
        }
    }
};
