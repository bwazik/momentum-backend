<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_confidential_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('added_at')->useCurrent();
            $table->timestamp('removed_at')->nullable();

            $table->index(['task_id', 'user_id']);
            $table->index(['task_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_confidential_participants');
    }
};
