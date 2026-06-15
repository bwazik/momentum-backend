<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_search_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->text('notes_ar')->nullable();
            $table->text('notes_en')->nullable();
            $table->timestamps();
            $table->unique('task_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE task_search_index ADD COLUMN search_vector_notes_ar tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(notes_ar,''))) STORED");
            DB::statement("ALTER TABLE task_search_index ADD COLUMN search_vector_notes_en tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(notes_en,''))) STORED");

            DB::statement('CREATE INDEX task_search_index_vector_ar_idx ON task_search_index USING GIN (search_vector_notes_ar)');
            DB::statement('CREATE INDEX task_search_index_vector_en_idx ON task_search_index USING GIN (search_vector_notes_en)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_search_index');
    }
};
