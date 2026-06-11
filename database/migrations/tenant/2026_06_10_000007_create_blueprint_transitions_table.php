<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprint_transitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blueprint_id')->constrained('blueprints')->cascadeOnDelete();
            $table->foreignId('from_stage_id')->constrained('blueprint_stages')->cascadeOnDelete();
            $table->foreignId('to_stage_id')->constrained('blueprint_stages')->cascadeOnDelete();
            $table->unsignedTinyInteger('transition_type');
            $table->boolean('return_reason_required')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_transitions');
    }
};
