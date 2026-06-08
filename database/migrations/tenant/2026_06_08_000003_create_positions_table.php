<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('department_id')->constrained('departments');
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->foreignId('reports_to_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('authority_grade_id')->constrained('authority_grades');
            $table->boolean('is_department_head')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('department_id');
            $table->index('authority_grade_id');
            $table->index('is_active');
            $table->index('is_department_head');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
