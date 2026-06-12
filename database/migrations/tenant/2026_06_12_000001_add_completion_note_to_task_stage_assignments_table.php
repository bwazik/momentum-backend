<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_stage_assignments', function (Blueprint $table) {
            $table->text('completion_note')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_stage_assignments', function (Blueprint $table) {
            $table->dropColumn('completion_note');
        });
    }
};
