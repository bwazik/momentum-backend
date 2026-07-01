<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_external_references', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedTinyInteger('reference_type');
            $table->string('reference_number', 100);
            $table->foreignId('external_entity_id')->nullable()->constrained('external_entities')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('task_id');
            $table->index('reference_number');
            $table->index(['task_id', 'reference_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_external_references');
    }
};
