<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprint_sub_stages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blueprint_stage_id')->constrained('blueprint_stages')->cascadeOnDelete();
            $table->foreignId('sla_policy_id')->nullable()->constrained('sla_policies')->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->smallInteger('sequence_order');
            $table->boolean('is_required')->default(true);
            $table->unsignedTinyInteger('assignment_type');
            $table->foreignId('assigned_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('assigned_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedTinyInteger('assignment_cardinality')->default(1);
            $table->unsignedTinyInteger('completion_rule')->default(1);
            $table->timestamps();
            $table->unique(['blueprint_stage_id', 'sequence_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_sub_stages');
    }
};
