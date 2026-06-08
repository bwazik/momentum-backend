<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('working_calendar_id')->constrained('working_calendars')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->date('holiday_date');
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();

            $table->unique(['working_calendar_id', 'holiday_date']);
            $table->index('holiday_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
