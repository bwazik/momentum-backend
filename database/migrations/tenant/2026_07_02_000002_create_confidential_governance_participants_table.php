<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confidential_governance_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_type');
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('blueprint_category_id')->nullable()->constrained('blueprint_categories')->nullOnDelete();
            // Default 3 = ClassificationLevel::Confidential (cannot use enum in migrations)
            $table->unsignedTinyInteger('applies_to_classification_level')->default(3);
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            $table->index(['position_id', 'revoked_at']);
            $table->index(['scope_type', 'scope_department_id', 'revoked_at']);
            $table->index('blueprint_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confidential_governance_participants');
    }
};
