<?php

namespace App\Modules\Organization\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['working_calendar_id', 'name_ar', 'name_en', 'holiday_date', 'is_recurring'])]
class PublicHoliday extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_recurring' => 'boolean',
            'holiday_date' => 'date',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkingCalendar::class, 'working_calendar_id');
    }
}
