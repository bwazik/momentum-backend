<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('event_type', 100);
            $table->unsignedTinyInteger('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->uuid('entity_public_id')->nullable();
            $table->unsignedTinyInteger('root_entity_type')->nullable();
            $table->unsignedBigInteger('root_entity_id')->nullable();
            $table->uuid('root_entity_public_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->jsonb('payload')->nullable();
            $table->uuid('impersonated_by_public_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['root_entity_type', 'root_entity_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
