<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_capability_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_type');
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->text('reason');

            $table->index(['user_id', 'revoked_at']);
            $table->index(['capability_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_capability_grants');
    }
};
