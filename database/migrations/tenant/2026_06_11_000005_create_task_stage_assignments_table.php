<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_stage_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('stage_instance_id')->nullable()->constrained('task_stage_instances')->cascadeOnDelete();
            $table->foreignId('sub_stage_instance_id')->nullable()->constrained('task_sub_stage_instances')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('delegated_from_user_id')->nullable()->constrained('users');
            $table->unsignedTinyInteger('assignment_role')->default(1);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reassigned_at')->nullable();
            $table->foreignId('reassigned_by_user_id')->nullable()->constrained('users');
            $table->text('reassignment_reason')->nullable();

            $table->index('user_id');
            $table->index(['task_id', 'stage_instance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_stage_assignments');
    }
};
