<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('delegator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedTinyInteger('scope_type');
            $table->unsignedBigInteger('blueprint_category_id')->nullable();
            $table->unsignedBigInteger('stage_type_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['delegator_user_id', 'is_active', 'starts_at', 'ends_at']);
            $table->index(['delegate_user_id', 'is_active']);
            $table->index('public_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
