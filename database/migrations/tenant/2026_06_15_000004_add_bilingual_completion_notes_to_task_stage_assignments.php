<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_stage_assignments', function (Blueprint $table) {
            $table->text('completion_note_ar')->nullable()->after('completion_note');
            $table->text('completion_note_en')->nullable()->after('completion_note_ar');
        });
    }

    public function down(): void
    {
        Schema::table('task_stage_assignments', function (Blueprint $table) {
            $table->dropColumn(['completion_note_ar', 'completion_note_en']);
        });
    }
};
