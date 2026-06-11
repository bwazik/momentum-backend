<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprint_stages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blueprint_id')->constrained('blueprints')->cascadeOnDelete();
            $table->foreignId('stage_type_id')->constrained('stage_types');
            $table->foreignId('sla_policy_id')->nullable()->constrained('sla_policies')->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->smallInteger('sequence_order');
            $table->unsignedTinyInteger('assignment_type');
            $table->foreignId('assigned_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('assigned_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedTinyInteger('assignment_cardinality')->default(1);
            $table->unsignedTinyInteger('completion_rule')->default(1);
            $table->foreignId('escalation_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['blueprint_id', 'sequence_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_stages');
    }
};
