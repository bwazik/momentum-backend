<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprint_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->smallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_categories');
    }
};
