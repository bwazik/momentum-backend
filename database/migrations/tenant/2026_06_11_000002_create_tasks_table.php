<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blueprint_id')->constrained('blueprints');
            $table->foreignId('priority_id')->constrained('task_priorities');
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description_ar');
            $table->text('description_en')->nullable();
            $table->unsignedTinyInteger('classification_level')->default(1);
            $table->foreignId('initiator_user_id')->constrained('users');
            $table->unsignedTinyInteger('status')->default(1);
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users');
            $table->softDeletes();

            $table->index('status');
            $table->index('blueprint_id');
            $table->index('initiator_user_id');
            $table->index('priority_id');
            $table->index(['status', 'classification_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
