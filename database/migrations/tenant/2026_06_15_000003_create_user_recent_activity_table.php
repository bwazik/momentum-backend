<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_recent_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedTinyInteger('activity_type');
            $table->timestamp('occurred_at');
        });

        DB::statement('CREATE INDEX user_recent_activity_user_id_occurred_at_idx ON user_recent_activity (user_id, occurred_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recent_activity');
    }
};
