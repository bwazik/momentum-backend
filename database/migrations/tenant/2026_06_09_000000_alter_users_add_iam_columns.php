<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_id')->unique()->after('id');
            $table->string('name_ar')->after('name');
            $table->string('mobile', 30)->nullable()->after('email');
            $table->string('employee_id', 50)->nullable()->unique()->after('mobile');
            $table->unsignedTinyInteger('preferred_language')->default(1)->after('employee_id');
            $table->boolean('is_active')->default(true)->after('preferred_language');
            $table->boolean('is_out_of_office')->default(false)->after('is_active');
            $table->foreignId('out_of_office_delegate_user_id')->nullable()
                ->constrained('users')->nullOnDelete()->after('is_out_of_office');
            $table->softDeletes()->after('updated_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'name_en');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name_en')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['out_of_office_delegate_user_id']);
            $table->dropColumn([
                'public_id',
                'name_ar',
                'mobile',
                'employee_id',
                'preferred_language',
                'is_active',
                'is_out_of_office',
                'out_of_office_delegate_user_id',
                'deleted_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name_en', 'name');
            $table->string('name')->nullable(false)->change();
        });
    }
};
