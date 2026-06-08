<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_grades', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->smallInteger('rank')->unsigned()->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index('rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_grades');
    }
};
