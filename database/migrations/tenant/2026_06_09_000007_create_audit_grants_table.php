<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_auditor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date_range_start');
            $table->date('date_range_end');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();

            $table->index(['external_auditor_user_id', 'revoked_at']);
            $table->index(['external_auditor_user_id', 'date_range_start', 'date_range_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_grants');
    }
};
