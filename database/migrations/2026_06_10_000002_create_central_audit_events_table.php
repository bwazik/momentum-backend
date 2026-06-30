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
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->nullable();
            $table->string('event_type', 100)->nullable();
            $table->string('entity_type');
            $table->unsignedTinyInteger('entity_type_int')->nullable();
            $table->string('entity_id');
            $table->uuid('entity_public_id')->nullable();
            $table->unsignedTinyInteger('root_entity_type')->nullable();
            $table->unsignedBigInteger('root_entity_id')->nullable();
            $table->uuid('root_entity_public_id')->nullable();
            $table->jsonb('payload')->nullable();
            $table->uuid('impersonated_by_public_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index('entity_type');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
