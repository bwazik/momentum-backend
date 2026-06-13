<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_timer_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('stage_instance_id')->nullable()->constrained('task_stage_instances')->cascadeOnDelete();
            $table->foreignId('sub_stage_instance_id')->nullable()->constrained('task_sub_stage_instances')->cascadeOnDelete();
            $table->foreignId('sla_policy_id')->constrained('sla_policies');
            $table->foreignId('working_calendar_id')->constrained('working_calendars');
            $table->timestamp('started_at');
            $table->timestamp('deadline_at');
            $table->timestamp('warning_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('elapsed_before_pause')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();

            $table->index(['task_id', 'status']);
            $table->index(['status', 'warning_at']);
            $table->index(['status', 'deadline_at']);
            $table->index('stage_instance_id');
            $table->index('sub_stage_instance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_timer_instances');
    }
};
