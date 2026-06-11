<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->smallInteger('sla_value');
            $table->unsignedTinyInteger('sla_unit');
            $table->smallInteger('warning_threshold_percentage')->default(75);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
