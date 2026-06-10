<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_position_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
            $table->index(['position_id', 'ended_at']);
            $table->index(['user_id', 'is_primary', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_position_assignments');
    }
};
