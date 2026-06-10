<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_capability_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_type');
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();

            $table->index(['position_id', 'revoked_at']);
            $table->index(['capability_id', 'revoked_at']);
            $table->index('scope_department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_capability_grants');
    }
};
