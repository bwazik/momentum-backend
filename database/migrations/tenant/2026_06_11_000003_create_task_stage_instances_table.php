<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_stage_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('blueprint_stage_id')->constrained('blueprint_stages');
            $table->unsignedSmallInteger('sequence_order');
            $table->foreignId('owning_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedTinyInteger('completion_rule');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_stage_instances');
    }
};
