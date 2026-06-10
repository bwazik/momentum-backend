<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_scope_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_type');
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedBigInteger('blueprint_category_id')->nullable();
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index('scope_department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_scope_grants');
    }
};
