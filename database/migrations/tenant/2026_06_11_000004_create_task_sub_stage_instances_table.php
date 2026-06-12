<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_sub_stage_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('parent_stage_instance_id')->constrained('task_stage_instances')->cascadeOnDelete();
            $table->foreignId('blueprint_sub_stage_id')->constrained('blueprint_sub_stages');
            $table->unsignedSmallInteger('sequence_order');
            $table->foreignId('owning_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('is_required');
            $table->unsignedTinyInteger('completion_rule');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_sub_stage_instances');
    }
};
