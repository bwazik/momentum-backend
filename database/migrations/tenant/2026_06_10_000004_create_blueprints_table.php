<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprints', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('category_id')->constrained('blueprint_categories');
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedTinyInteger('scope');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprints');
    }
};
