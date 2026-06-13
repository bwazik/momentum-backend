<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('stage_instance_id')->nullable()->constrained('task_stage_instances')->cascadeOnDelete();
            $table->foreignId('sub_stage_instance_id')->nullable()->constrained('task_sub_stage_instances')->cascadeOnDelete();
            $table->foreignId('sla_timer_instance_id')->nullable()->constrained('sla_timer_instances')->nullOnDelete();
            $table->unsignedTinyInteger('escalation_type');
            $table->foreignId('escalated_to_user_id')->constrained('users');
            $table->foreignId('escalated_to_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('escalated_by_user_id')->nullable()->constrained('users');
            $table->text('reason');
            $table->unsignedTinyInteger('status')->default(1);
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'status']);
            $table->index(['escalated_to_user_id', 'status']);
            $table->index('escalation_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalations');
    }
};
