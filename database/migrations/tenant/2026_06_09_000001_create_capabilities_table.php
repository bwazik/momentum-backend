<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('key')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('is_system_defined')->default(true);
            $table->timestamps();

            $table->index('is_system_defined');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capabilities');
    }
};
