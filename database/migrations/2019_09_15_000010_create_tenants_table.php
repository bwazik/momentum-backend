<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('slug')->unique();
            $table->string('domain')->nullable();
            $table->string('database_name')->unique();
            $table->string('logo_path')->nullable();
            $table->tinyInteger('default_language')->default(1);
            $table->string('timezone')->default('Asia/Riyadh');
            $table->boolean('is_active')->default(true);
            $table->jsonb('settings')->nullable();
            $table->json('data')->nullable(); // Required by stancl/tenancy for unmapped attributes
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
