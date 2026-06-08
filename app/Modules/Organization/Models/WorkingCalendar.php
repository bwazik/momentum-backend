<?php

namespace App\Modules\Organization\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name_ar', 'name_en', 'working_days', 'working_hours_start', 'working_hours_end', 'timezone', 'is_default'])]
class WorkingCalendar extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(PublicHoliday::class);
    }
}
