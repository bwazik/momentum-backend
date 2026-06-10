<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_out_of_office')) {
                $table->boolean('is_out_of_office')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('users', 'out_of_office_delegate_user_id')) {
                $table->foreignId('out_of_office_delegate_user_id')->nullable()
                    ->constrained('users')->nullOnDelete()->after('is_out_of_office');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['out_of_office_delegate_user_id']);
            $table->dropColumn(['is_out_of_office', 'out_of_office_delegate_user_id']);
        });
    }
};
