<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('parent_comment_id')->nullable()->constrained('comments');
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index('task_id');
            $table->index(['task_id', 'parent_comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
