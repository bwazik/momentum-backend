<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->string('name_ar');
                $table->string('name_en')->nullable();
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('mobile', 30)->nullable();
                $table->string('employee_id', 50)->nullable()->unique();
                $table->unsignedTinyInteger('account_type')->default(1);
                $table->unsignedTinyInteger('preferred_language')->default(1);
                $table->boolean('is_active')->default(true);
                $table->rememberToken();
                $table->softDeletes();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
